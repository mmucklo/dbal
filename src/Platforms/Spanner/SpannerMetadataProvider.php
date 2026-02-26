<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\Spanner;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SpannerPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\MatchType;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Metadata\DatabaseMetadataRow;
use Doctrine\DBAL\Schema\Metadata\ForeignKeyConstraintColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\IndexColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\MetadataProvider;
use Doctrine\DBAL\Schema\Metadata\PrimaryKeyConstraintColumnRow;
use Doctrine\DBAL\Schema\Metadata\SchemaMetadataRow;
use Doctrine\DBAL\Schema\Metadata\SequenceMetadataRow;
use Doctrine\DBAL\Schema\Metadata\TableColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\TableMetadataRow;
use Doctrine\DBAL\Schema\Metadata\ViewMetadataRow;
use Doctrine\DBAL\Types\Exception\TypesException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Exception;
use Throwable;

use function array_change_key_case;
use function assert;
use function count;
use function is_string;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;

use const CASE_UPPER;

/**
 * Cloud Spanner metadata provider using INFORMATION_SCHEMA.
 */
final class SpannerMetadataProvider implements MetadataProvider
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AbstractPlatform $platform,
    ) {
    }

    /** @return iterable<DatabaseMetadataRow> */
    public function getAllDatabaseNames(): iterable
    {
        $sql  = 'SELECT CATALOG_NAME FROM INFORMATION_SCHEMA.SCHEMATA';
        $rows = $this->connection->fetchAllAssociative($sql);
        foreach ($rows as $row) {
            $name = $row['CATALOG_NAME'] ?? '';
            assert(is_string($name) && $name !== '');

            yield new DatabaseMetadataRow($name);
        }
    }

    /** @return iterable<SchemaMetadataRow> */
    public function getAllSchemaNames(): iterable
    {
        $sql  = 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ' .
               "WHERE SCHEMA_NAME NOT IN ('INFORMATION_SCHEMA', 'SPANNER_SYS', 'pg_catalog')";
        $rows = $this->connection->fetchAllAssociative($sql);
        foreach ($rows as $row) {
            $row  = array_change_key_case($row, CASE_UPPER);
            $name = $row['SCHEMA_NAME'] ?? '';
            assert(is_string($name));

            if ($name === $this->getDefaultSchemaName()) {
                yield new SchemaMetadataRow('default');

                continue;
            }

            if ($name === '') {
                continue;
            }

            yield new SchemaMetadataRow($name);
        }
    }

    /** @return iterable<TableMetadataRow> */
    public function getAllTableNames(): iterable
    {
        $sql  = 'SELECT TABLE_NAME, TABLE_SCHEMA FROM INFORMATION_SCHEMA.TABLES ' .
                "WHERE TABLE_SCHEMA NOT IN ('INFORMATION_SCHEMA', 'SPANNER_SYS', 'pg_catalog') " .
                "AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
        $rows = $this->connection->fetchAllAssociative($sql);
        foreach ($rows as $row) {
            $name   = $row['TABLE_NAME'] ?? '';
            $schema = $row['TABLE_SCHEMA'] ?? '';
            assert(is_string($name) && $name !== '');

            yield new TableMetadataRow($schema === '' ? null : $schema, $name, []);
        }
    }

    /**
     * @return iterable<TableColumnMetadataRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getTableColumnsForAllTables(): iterable
    {
        return $this->getTableColumnsForTable(null, '%');
    }

    /**
     * @return iterable<TableColumnMetadataRow>
     *
     * @throws Exception
     * @throws DBALException
     * @throws TypesException
     */
    public function getTableColumnsForTable(?string $schemaName, string $tableName): iterable
    {
        $sql = 'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, SPANNER_TYPE ';

        try {
            $this->connection->executeQuery('SELECT IS_IDENTITY FROM INFORMATION_SCHEMA.COLUMNS LIMIT 1');
            $sql .= ', IS_IDENTITY ';
        } catch (Throwable) {
            $sql .= ", 'NO' as IS_IDENTITY ";
        }

        $sql   .= 'FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ?';
        $params = [$this->getSchemaName($schemaName)];

        if ($tableName !== '%') {
            $sql     .= ' AND TABLE_NAME = ?';
            $params[] = $tableName;
        }

        $sql .= ' ORDER BY TABLE_NAME, ORDINAL_POSITION';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (Throwable $e) {
            // Suppress Protobuf descriptor pool errors which can occur in the emulator during DDL changes.
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        foreach ($rows as $row) {
            $row         = array_change_key_case($row, CASE_UPPER);
            $spannerType = (string) ($row['SPANNER_TYPE'] ?? '');
            $dbType      = strtolower((string) ($row['DATA_TYPE'] ?? ''));
            if ($dbType === '') {
                $dbType = strtolower($spannerType);
            }

            $length = null;
            if (preg_match('/\((\d+|MAX)\)/', $dbType, $matches) === 1) {
                if ($matches[1] !== 'MAX') {
                    $length = (int) $matches[1];
                }
            }

            $dbTypeBase = (string) preg_replace('/\s*\(.*\)/', '', $dbType);
            $typeStr    = $this->platform->getDoctrineTypeMapping($dbTypeBase);

            if ($dbTypeBase === 'bytes') {
                $typeStr = $length === null ? Types::BLOB : Types::BINARY;
            } elseif ($dbTypeBase === 'string' && $length === null) {
                $typeStr = Types::TEXT;
            }

            $default = $row['COLUMN_DEFAULT'] ?? null;
            if ($default !== null) {
                $default = trim((string) $default);
                if (preg_match('/^\((.*)\)$/', $default, $matches) === 1) {
                     $default = $matches[1];
                }

                if (preg_match("/^'(.*)'$/", $default, $matches) === 1) {
                    $default = str_replace("''", "'", $matches[1]);
                }
            }

            $isAutoincrement = ($row['IS_IDENTITY'] ?? 'NO') === 'YES';
            if (! $isAutoincrement && ($row['COLUMN_DEFAULT'] ?? '') !== '') {
                 $colDefault = (string) ($row['COLUMN_DEFAULT'] ?? '');
                if (str_contains($colDefault, 'BIT_REVERSED_POSITIVE')) {
                     $isAutoincrement = true;
                }
            }

            $options = [
                'notnull' => ($row['IS_NULLABLE'] ?? 'YES') === 'NO',
                'default' => $default,
                'length'  => $length,
                'autoincrement' => $isAutoincrement,
            ];

            if ($dbTypeBase === 'numeric') {
                $options['precision'] = 38;
                $options['scale']     = 9;
            }

            $columnName = $row['COLUMN_NAME'] ?? '';
            assert(is_string($columnName) && $columnName !== '');

            $column = new Column($columnName, Type::getType($typeStr), $options);

            $tableNameFromRow = $row['TABLE_NAME'] ?? '';
            assert(is_string($tableNameFromRow) && $tableNameFromRow !== '');

            yield new TableColumnMetadataRow(
                null,
                $tableNameFromRow,
                $column,
            );
        }
    }

    /**
     * @return iterable<IndexColumnMetadataRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getIndexColumnsForAllTables(): iterable
    {
        return $this->getIndexColumnsForTable(null, '%');
    }

    /**
     * @return iterable<IndexColumnMetadataRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getIndexColumnsForTable(?string $schemaName, string $tableName): iterable
    {
        $schema = $this->getSchemaName($schemaName);

        $indexesSql = 'SELECT TABLE_NAME, INDEX_NAME, IS_UNIQUE FROM INFORMATION_SCHEMA.INDEXES ' .
                      'WHERE TABLE_SCHEMA = ?';
        $params     = [$schema];
        if ($tableName !== '%') {
            $indexesSql .= ' AND TABLE_NAME = ?';
            $params[]    = $tableName;
        }

        try {
            $indexes = $this->connection->fetchAllAssociative($indexesSql, $params);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        $columnsSql = 'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, ORDINAL_POSITION, IS_NULLABLE ' .
                      'FROM INFORMATION_SCHEMA.INDEX_COLUMNS WHERE TABLE_SCHEMA = ?';
        $params2    = [$schema];
        if ($tableName !== '%') {
            $columnsSql .= ' AND TABLE_NAME = ?';
            $params2[]   = $tableName;
        }

        $columnsSql .= ' ORDER BY TABLE_NAME, INDEX_NAME, ORDINAL_POSITION';

        try {
            $indexColumns = $this->connection->fetchAllAssociative($columnsSql, $params2);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        $aggregated = MetadataAggregator::aggregateIndexes($indexes, $indexColumns);

        foreach ($aggregated as $tableNameVal => $indexesMap) {
            foreach ($indexesMap as $indexNameVal => $data) {
                foreach ($data['columns'] as $col) {
                    $columnName = (string) ($col['COLUMN_NAME'] ?? '');
                    if ($tableNameVal === '' || $indexNameVal === '' || $columnName === '') {
                        continue;
                    }

                    yield new IndexColumnMetadataRow(
                        null,
                        $tableNameVal,
                        $indexNameVal,
                        $data['is_unique'] ? IndexType::UNIQUE : IndexType::REGULAR,
                        false,
                        null,
                        $columnName,
                        null,
                    );
                }
            }
        }
    }

    /**
     * @return iterable<PrimaryKeyConstraintColumnRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getPrimaryKeyConstraintColumnsForAllTables(): iterable
    {
        return $this->getPrimaryKeyConstraintColumnsForTable(null, '%');
    }

    /**
     * @return iterable<PrimaryKeyConstraintColumnRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getPrimaryKeyConstraintColumnsForTable(?string $schemaName, string $tableName): iterable
    {
        $sql = 'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, ORDINAL_POSITION ' .
               'FROM INFORMATION_SCHEMA.INDEX_COLUMNS ' .
               "WHERE TABLE_SCHEMA = ? AND INDEX_NAME = 'PRIMARY_KEY'";

        $params = [$this->getSchemaName($schemaName)];
        if ($tableName !== '%') {
            $sql     .= ' AND TABLE_NAME = ?';
            $params[] = $tableName;
        }

        $sql .= ' ORDER BY TABLE_NAME, ORDINAL_POSITION';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_UPPER);

            $tableNameVal  = $row['TABLE_NAME'] ?? '';
            $constraintVal = $row['INDEX_NAME'] ?? '';
            $colNameVal    = $row['COLUMN_NAME'] ?? '';

            if ($constraintVal === 'PRIMARY_KEY') {
                $constraintVal = 'primary';
            }

            assert(is_string($tableNameVal) && $tableNameVal !== '');
            assert(is_string($constraintVal) && $constraintVal !== '');
            assert(is_string($colNameVal) && $colNameVal !== '');

            yield new PrimaryKeyConstraintColumnRow(
                null,
                $tableNameVal,
                $constraintVal,
                false,
                $colNameVal,
            );
        }
    }

    /**
     * @return iterable<ForeignKeyConstraintColumnMetadataRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getForeignKeyConstraintColumnsForAllTables(): iterable
    {
        return $this->getForeignKeyConstraintColumnsForTable(null, '%');
    }

    /**
     * @return iterable<ForeignKeyConstraintColumnMetadataRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getForeignKeyConstraintColumnsForTable(?string $schemaName, string $tableName): iterable
    {
        $schema = $this->getSchemaName($schemaName);

        $kcuSql = 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE ' .
                  'WHERE TABLE_SCHEMA = ?';
        $params = [$schema];
        if ($tableName !== '%') {
            $kcuSql  .= ' AND TABLE_NAME = ?';
            $params[] = $tableName;
        }

        $kcuSql .= ' ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION';

        try {
            $kcu = $this->connection->fetchAllAssociative($kcuSql, $params);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        if (count($kcu) === 0) {
            return;
        }

        $rcSql = 'SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS ' .
                 'WHERE CONSTRAINT_SCHEMA = ?';
        try {
            $rcs = $this->connection->fetchAllAssociative($rcSql, [$schema]);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        $kcu2Sql = 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE ' .
                   'WHERE TABLE_SCHEMA = ?';
        try {
            $kcu2 = $this->connection->fetchAllAssociative($kcu2Sql, [$schema]);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        $aggregated = MetadataAggregator::aggregateForeignKeys($kcu, $rcs, $kcu2);

        foreach ($aggregated as $tableNameVal => $constraints) {
            foreach ($constraints as $constraintName => $data) {
                foreach ($data['columns'] as $idx => $colName) {
                    $refTableName = (string) $data['referenced_table'];
                    $refColName   = (string) $data['referenced_columns'][$idx];

                    if ($tableNameVal === '' || $colName === '' || $refTableName === '' || $refColName === '') {
                        continue;
                    }

                    yield new ForeignKeyConstraintColumnMetadataRow(
                        null,
                        $tableNameVal,
                        null,
                        $constraintName === '' ? null : $constraintName,
                        null,
                        $refTableName,
                        MatchType::SIMPLE,
                        ReferentialAction::NO_ACTION,
                        ReferentialAction::NO_ACTION,
                        false,
                        false,
                        $colName,
                        $refColName,
                    );
                }
            }
        }
    }

    /**
     * @return iterable<TableMetadataRow>
     *
     * @throws DBALException
     */
    public function getTableOptionsForAllTables(): iterable
    {
        $sql  = 'SELECT TABLE_NAME, TABLE_SCHEMA FROM INFORMATION_SCHEMA.TABLES ' .
                "WHERE TABLE_SCHEMA NOT IN ('INFORMATION_SCHEMA', 'SPANNER_SYS', 'pg_catalog') " .
                "AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
        $rows = $this->connection->fetchAllAssociative($sql);
        foreach ($rows as $row) {
            $name   = $row['TABLE_NAME'] ?? '';
            $schema = $row['TABLE_SCHEMA'] ?? '';
            assert(is_string($name) && $name !== '');

            yield new TableMetadataRow($schema === '' ? null : $schema, $name, []);
        }
    }

    /** @return iterable<TableMetadataRow> */
    public function getTableOptionsForTable(?string $schemaName, string $tableName): iterable
    {
        assert($tableName !== '');

        yield new TableMetadataRow($schemaName, $tableName, []);
    }

    /**
     * @return iterable<ViewMetadataRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getAllViews(): iterable
    {
        $sql = 'SELECT TABLE_NAME, TABLE_SCHEMA, VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS ' .
               "WHERE TABLE_SCHEMA NOT IN ('INFORMATION_SCHEMA', 'SPANNER_SYS', 'pg_catalog') " .
               'ORDER BY TABLE_NAME';

        try {
            $rows = $this->connection->fetchAllAssociative($sql);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        foreach ($rows as $row) {
            $viewName = $row['TABLE_NAME'] ?? '';
            $schema   = $row['TABLE_SCHEMA'] ?? '';
            assert(is_string($viewName) && $viewName !== '');

            $viewDef = $row['VIEW_DEFINITION'] ?? '';
            assert(is_string($viewDef));

            yield new ViewMetadataRow($schema === '' ? null : $schema, $viewName, $viewDef);
        }
    }

    /**
     * @return iterable<SequenceMetadataRow>
     *
     * @throws Exception
     * @throws DBALException
     */
    public function getAllSequences(): iterable
    {
        $sql = 'SELECT * FROM INFORMATION_SCHEMA.SEQUENCES ' .
               "WHERE TABLE_SCHEMA NOT IN ('INFORMATION_SCHEMA', 'SPANNER_SYS', 'pg_catalog') ORDER BY NAME";

        try {
            $rows = $this->connection->fetchAllAssociative($sql);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'descriptor pool')) {
                 return;
            }

            throw $e;
        }

        foreach ($rows as $row) {
            $row    = array_change_key_case($row, CASE_UPPER);
            $name   = $row['SEQUENCE_NAME'] ?? $row['NAME'] ?? 'unknown';
            $schema = $row['TABLE_SCHEMA'] ?? $row['SEQUENCE_SCHEMA'] ?? '';
            assert(is_string($name) && $name !== '');

            yield new SequenceMetadataRow($schema === '' ? null : $schema, $name, 1, 1, null);
        }
    }

    private function getSchemaName(?string $schemaName): string
    {
        if ($schemaName === null || $schemaName === 'default') {
            return $this->getDefaultSchemaName();
        }

        return $schemaName;
    }

    private function getDefaultSchemaName(): string
    {
        return $this->platform instanceof SpannerPlatform ? '' : 'public';
    }
}
