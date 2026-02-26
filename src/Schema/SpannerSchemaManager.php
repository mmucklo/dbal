<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Driver\Spanner\Driver as SpannerDriver;
use Doctrine\DBAL\Driver\Spanner\NativeSpannerProvider;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\Spanner\MetadataAggregator;
use Doctrine\DBAL\Platforms\Spanner\SpannerMetadataProvider;
use Doctrine\DBAL\Platforms\SpannerPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Metadata\MetadataProvider;
use Doctrine\DBAL\Types\Type;
use Exception;
use Google\Cloud\Spanner\SpannerClient;
use LogicException;
use Throwable;

use function array_change_key_case;
use function assert;
use function count;
use function is_object;
use function is_string;
use function ltrim;
use function method_exists;
use function preg_match;
use function preg_replace;
use function str_ends_with;
use function substr;

use const CASE_UPPER;

/**
 * Cloud Spanner schema manager.
 *
 * @extends AbstractSchemaManager<AbstractPlatform>
 */
final class SpannerSchemaManager extends AbstractSchemaManager
{
    public function createMetadataProvider(): MetadataProvider
    {
        return new SpannerMetadataProvider($this->connection, $this->platform);
    }

    /**
     * {@inheritDoc}
     *
     * @return list<non-empty-string>
     */
    public function listTableNames(): array
    {
        $schemaName = $this->determineCurrentSchemaName() ?? '';
        $sql        = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ' .
            $this->connection->quote($schemaName) .
            " AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";

        return $this->connection->fetchFirstColumn($sql);
    }

    /** @throws DBALException */
    public function dropTable(string $name): void
    {
        $statements = [];

        // Spanner requires dropping foreign keys and indexes before dropping the table.
        try {
            $fks = $this->listTableForeignKeys($name);
            foreach ($fks as $fk) {
                $statements[] = $this->platform->getDropForeignKeySQL($fk->getQuotedName($this->platform), $name);
            }

            $indexes = $this->listTableIndexes($name);
            foreach ($indexes as $index) {
                if ($index->isPrimary()) {
                    continue;
                }

                $statements[] = $this->platform->getDropIndexSQL($index->getQuotedName($this->platform), $name);
            }
        } catch (Throwable) {
            // Ignore errors during FK/Index discovery if table doesn't exist or is invalid.
        }

        $statements[] = $this->platform->getDropTableSQL($name);

        if (count($statements) <= 0) {
            return;
        }

        $driverConn = $this->connection->getNativeConnection();
        if (is_object($driverConn) && method_exists($driverConn, 'executeDdlBatch')) {
            $driverConn->executeDdlBatch($statements);
        } else {
            foreach ($statements as $sql) {
                try {
                    $this->connection->executeStatement($sql);
                } catch (Throwable) {
                    // Ignore
                }
            }
        }
    }

    /**
     * @throws Exception
     * @throws DBALException
     */
    public function createDatabase(string $database): void
    {
        $spanner    = $this->getSpannerClient();
        $instanceId = $this->getSpannerInstanceId();

        $instance = $spanner->instance($instanceId);
        $instance->createDatabase($database)->pollUntilComplete();
    }

    /**
     * @throws Exception
     * @throws DBALException
     */
    public function dropDatabase(string $database): void
    {
        $spanner    = $this->getSpannerClient();
        $instanceId = $this->getSpannerInstanceId();

        $instance = $spanner->instance($instanceId);
        $instance->database($database)->drop();
    }

    /**
     * @return SpannerClient
     *
     * @throws Exception
     * @throws DBALException
     */
    private function getSpannerClient(): object
    {
        $driver = $this->connection->getDriver();
        if ($driver instanceof SpannerDriver) {
             return $driver->getSpannerClient();
        }

        $native = $this->connection->getNativeConnection();
        if ($native instanceof NativeSpannerProvider) {
             return $native->getSpannerClient();
        }

        // Deep unwrap
        $driverConn = $this->connection->getNativeConnection();
        if ($driverConn instanceof NativeSpannerProvider) {
             return $driverConn->getSpannerClient();
        }

        throw new Exception(
            'Could not access SpannerClient from connection: ' .
            (is_object($native) ? $native::class : 'unknown'),
        );
    }

    /**
     * @throws Exception
     * @throws DBALException
     */
    private function getSpannerInstanceId(): string
    {
        $native = $this->connection->getNativeConnection();
        if ($native instanceof NativeSpannerProvider) {
             return $native->getSpannerInstanceId();
        }

        // Deep unwrap
        $driverConn = $this->connection->getNativeConnection();
        if ($driverConn instanceof NativeSpannerProvider) {
             return $driverConn->getSpannerInstanceId();
        }

        $params     = $this->connection->getParams();
        $instanceId = $params['instance'] ?? $params['instanceId'] ?? $params['instance_id'] ?? '';

        if (! is_string($instanceId) || $instanceId === '') {
             $instanceId = 'test-instance';
        }

        return $instanceId;
    }

    /** @throws DBALException */
    protected function selectTableNames(string $databaseName): Result
    {
        assert($this->platform instanceof SpannerPlatform);

        return $this->connection->executeQuery($this->platform->getListTablesSQL());
    }

    /** @throws DBALException */
    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        $schemaName = $this->determineCurrentSchemaName() ?? '';

        $sql = 'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, SPANNER_TYPE ' .
               'FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ' . $this->connection->quote($schemaName);

        if ($tableName !== null) {
            $sql .= ' AND TABLE_NAME = ' . $this->connection->quote($tableName);
        }

        $sql .= ' ORDER BY ORDINAL_POSITION';

        return $this->connection->executeQuery($sql);
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        throw new LogicException('Not used in Spanner');
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        throw new LogicException('Not used in Spanner');
    }

    /** @return list<array<string, mixed>> */
    protected function fetchIndexColumns(string $databaseName, ?string $tableName = null): array
    {
        $schemaName = $this->determineCurrentSchemaName() ?? '';

        // Spanner emulator hangs on JOINs in INFORMATION_SCHEMA, so we fetch separately
        $indexesSql = 'SELECT TABLE_NAME, INDEX_NAME, IS_UNIQUE FROM INFORMATION_SCHEMA.INDEXES ' .
                      'WHERE TABLE_SCHEMA = ' . $this->connection->quote($schemaName);
        if ($tableName !== null) {
            $indexesSql .= ' AND TABLE_NAME = ' . $this->connection->quote($tableName);
        }

        $indexes = $this->connection->fetchAllAssociative($indexesSql);

        $columnsSql = 'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, ORDINAL_POSITION, IS_NULLABLE ' .
                      'FROM INFORMATION_SCHEMA.INDEX_COLUMNS WHERE TABLE_SCHEMA = ' .
                      $this->connection->quote($schemaName);
        if ($tableName !== null) {
            $columnsSql .= ' AND TABLE_NAME = ' . $this->connection->quote($tableName);
        }

        $columnsSql .= ' ORDER BY TABLE_NAME, INDEX_NAME, ORDINAL_POSITION';

        $indexColumns = $this->connection->fetchAllAssociative($columnsSql);

        $aggregated = MetadataAggregator::aggregateIndexes($indexes, $indexColumns);

        $result = [];
        foreach ($aggregated as $tableNameVal => $indexesMap) {
            foreach ($indexesMap as $indexNameVal => $data) {
                foreach ($data['columns'] as $col) {
                    $col['key_name']    = $indexNameVal;
                    $col['primary']     = false;
                    $col['column_name'] = $col['COLUMN_NAME'];
                    $col['non_unique']  = ! $data['is_unique'];
                    $result[]           = $col;
                }
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    protected function fetchForeignKeyColumns(string $databaseName, ?string $tableName = null): array
    {
        $schemaName = $this->determineCurrentSchemaName() ?? '';

        $kcuSql = 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION ' .
                  'FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ' .
                  $this->connection->quote($schemaName);
        if ($tableName !== null) {
            $kcuSql .= ' AND TABLE_NAME = ' . $this->connection->quote($tableName);
        }

        $kcuSql .= ' ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION';
        $kcu     = $this->connection->fetchAllAssociative($kcuSql);

        if (count($kcu) === 0) {
            return [];
        }

        $rcSql = 'SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS ' .
                 'WHERE CONSTRAINT_SCHEMA = ' . $this->connection->quote($schemaName);
        $rcs   = $this->connection->fetchAllAssociative($rcSql);

        $kcu2Sql = 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION ' .
                   'FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ' .
                   $this->connection->quote($schemaName);
        $kcu2    = $this->connection->fetchAllAssociative($kcu2Sql);

        $aggregated = MetadataAggregator::aggregateForeignKeys($kcu, $rcs, $kcu2);

        $result = [];
        foreach ($aggregated as $tableNameVal => $constraints) {
            foreach ($constraints as $constraintName => $data) {
                foreach ($data['columns'] as $idx => $colName) {
                    $result[] = [
                        'TABLE_NAME'             => $tableNameVal,
                        'CONSTRAINT_NAME'        => $constraintName,
                        'COLUMN_NAME'            => $colName,
                        'REFERENCED_TABLE_NAME'  => $data['referenced_table'],
                        'REFERENCED_COLUMN_NAME' => $data['referenced_columns'][$idx],
                    ];
                }
            }
        }

        return $result;
    }

    /** @return array<non-empty-string, array<string, mixed>> */
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        return [];
    }

    /** @param array<string, mixed> $tableColumn */
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $tableColumn = array_change_key_case($tableColumn, CASE_UPPER);

        $dbType = (string) ($tableColumn['DATA_TYPE'] ?? $tableColumn['SPANNER_TYPE'] ?? '');
        $length = null;
        if (preg_match('/\((\d+|MAX)\)/', $dbType, $matches) === 1) {
            if ($matches[1] !== 'MAX') {
                $length = (int) $matches[1];
            }
        }

        $dbTypeBase = (string) preg_replace('/\s*\(.*\)/', '', $dbType);
        $type       = $this->platform->getDoctrineTypeMapping($dbTypeBase);

        $default = $tableColumn['COLUMN_DEFAULT'] ?? null;
        if (is_string($default)) {
            $default = ltrim($default, '(');
            if (str_ends_with($default, ')')) {
                $default = substr($default, 0, -1);
            }
        }

        $options = [
            'length'  => $length,
            'notnull' => ($tableColumn['IS_NULLABLE'] ?? 'YES') === 'NO',
            'default' => $default,
        ];

        if ($dbTypeBase === 'numeric') {
            $options['precision'] = 38;
            $options['scale']     = 9;
        }

        $columnName = $tableColumn['COLUMN_NAME'] ?? '';
        assert(is_string($columnName) && $columnName !== '');

        return new Column($columnName, Type::getType($type), $options);
    }

    /** @param array<string, mixed> $table */
    protected function _getPortableTableDefinition(array $table): string
    {
        $table = array_change_key_case($table, CASE_UPPER);
        $name  = $table['TABLE_NAME'] ?? '';
        assert(is_string($name) && $name !== '');

        return $name;
    }

    /** @param array<string, mixed> $view */
    protected function _getPortableViewDefinition(array $view): View
    {
        $view = array_change_key_case($view, CASE_UPPER);
        $name = $view['TABLE_NAME'] ?? '';
        $def  = $view['VIEW_DEFINITION'] ?? '';
        assert(is_string($name) && $name !== '');
        assert(is_string($def));

        return new View($name, $def);
    }

    /** @param array<string, mixed> $tableForeignKey */
    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
    {
        $tableForeignKey = array_change_key_case($tableForeignKey, CASE_UPPER);

        $name         = $tableForeignKey['CONSTRAINT_NAME'] ?? '';
        $columnName   = $tableForeignKey['COLUMN_NAME'] ?? '';
        $refTableName = $tableForeignKey['REFERENCED_TABLE_NAME'] ?? '';
        $refColName   = $tableForeignKey['REFERENCED_COLUMN_NAME'] ?? '';

        assert(is_string($name) && $name !== '');
        assert(is_string($columnName) && $columnName !== '');
        assert(is_string($refTableName) && $refTableName !== '');
        assert(is_string($refColName) && $refColName !== '');

        return new ForeignKeyConstraint(
            [$columnName],
            $refTableName,
            [$refColName],
            $name,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return list<string>
     */
    public function listSchemaNames(): array
    {
        $sql = 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ' .
            "WHERE SCHEMA_NAME NOT IN ('INFORMATION_SCHEMA', 'SPANNER_SYS', 'pg_catalog')";

        return $this->connection->fetchFirstColumn($sql);
    }

    protected function determineCurrentSchemaName(): ?string
    {
        return $this->platform instanceof SpannerPlatform ? null : 'public';
    }
}
