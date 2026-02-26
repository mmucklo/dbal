<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\Keywords\SpannerKeywords;
use Doctrine\DBAL\Platforms\Spanner\SpannerMetadataProvider;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Metadata\MetadataProvider;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\SpannerSchemaManager;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Exception\TypesException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

use function array_values;
use function implode;
use function in_array;
use function ltrim;
use function reset;
use function str_replace;
use function strpos;
use function strtoupper;
use function substr;

/**
 * Provides the behavior, features and SQL dialect of the Google Cloud Spanner database platform (GoogleSQL dialect).
 */
class SpannerPlatform extends AbstractPlatform
{
    public function __construct()
    {
        parent::__construct(UnquotedIdentifierFolding::NONE);
    }

    /** @param array<string, mixed> $column */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'BOOL';
    }

    /** @param array<string, mixed> $column */
    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return 'INT64';
    }

    /** @param array<string, mixed> $column */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return 'INT64';
    }

    /** @param array<string, mixed> $column */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return 'INT64';
    }

    /** @param array<string, mixed> $column */
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        return '';
    }

    protected function getVarcharTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'STRING(' . ($length ?? 'MAX') . ')';
    }

    protected function getCharTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'STRING(' . ($length ?? 'MAX') . ')';
    }

    /** @param array<string, mixed> $column */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'STRING(MAX)';
    }

    /** @param array<string, mixed> $column */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'BYTES(MAX)';
    }

    protected function getBinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'BYTES(' . ($length ?? 'MAX') . ')';
    }

    protected function getVarbinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'BYTES(' . ($length ?? 'MAX') . ')';
    }

    /** @param array<string, mixed> $column */
    public function getDecimalTypeDeclarationSQL(array $column): string
    {
        return 'NUMERIC';
    }

    /** @param array<string, mixed> $column */
    public function getFloatDeclarationSQL(array $column): string
    {
        return 'FLOAT64';
    }

    /** @param array<string, mixed> $column */
    public function getSmallFloatDeclarationSQL(array $column): string
    {
        return 'FLOAT64';
    }

    /** @param array<string, mixed> $column */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMP';
    }

    /** @param array<string, mixed> $column */
    public function getDateTypeDeclarationSQL(array $column): string
    {
        return 'DATE';
    }

    /** @param array<string, mixed> $column */
    public function getTimeTypeDeclarationSQL(array $column): string
    {
        return 'STRING(MAX)';
    }

    /** @param array<string, mixed> $column */
    public function getJsonTypeDeclarationSQL(array $column): string
    {
        return 'JSON';
    }

    /** @param array<string, mixed> $column */
    public function getGuidTypeDeclarationSQL(array $column): string
    {
        return 'STRING(36)';
    }

    public function getListDatabasesSQL(): string
    {
        return 'SELECT CATALOG_NAME FROM INFORMATION_SCHEMA.SCHEMATA';
    }

    public function getListTablesSQL(): string
    {
        return "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '' AND TABLE_TYPE = 'BASE TABLE'";
    }

    public function getListViewsSQL(string $database): string
    {
        return 'SELECT TABLE_NAME, VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS '
            . "WHERE TABLE_SCHEMA NOT IN ('INFORMATION_SCHEMA', 'SPANNER_SYS')";
    }

    /** @param array<string, mixed> $column */
    protected function getCreateParamsSQL(array $column): string
    {
        return '';
    }

    public function getDropTableSQL(string $tableName): string
    {
        return 'DROP TABLE ' . $tableName;
    }

    public function getCreateIndexSQL(Index $index, string $tableName): string
    {
        $sql = 'CREATE ';
        if ($index->isUnique()) {
            $sql .= 'UNIQUE ';
        }

        $sql .= 'INDEX ' . $index->getQuotedName($this) . ' ON ' . $tableName;
        $sql .= ' (' . implode(', ', array_values($index->getQuotedColumns($this))) . ')';

        return $sql;
    }

    public function getDropIndexSQL(string $name, string $tableName): string
    {
        $unquotedName = str_replace(['`', '"'], '', $name);
        if (strtoupper($unquotedName) === 'PRIMARY_KEY' || $unquotedName === 'primary') {
            // Spanner's PRIMARY_KEY index cannot be dropped.
            return '-- ignore drop index ' . $name;
        }

        return 'DROP INDEX ' . $name;
    }

    public function getCreateUniqueConstraintSQL(UniqueConstraint $constraint, string $tableName): string
    {
        $index = new Index(
            $constraint->getName(),
            $constraint->getColumns(),
            true,
            false,
            [],
            [],
        );

        return $this->getCreateIndexSQL($index, $tableName);
    }

    public function getDropUniqueConstraintSQL(string $name, string $tableName): string
    {
        return $this->getDropIndexSQL($name, $tableName);
    }

    public function getDropForeignKeySQL(string $name, string $tableName): string
    {
        return 'ALTER TABLE ' . $tableName . ' DROP CONSTRAINT ' . $name;
    }

    public function getCreateForeignKeySQL(ForeignKeyConstraint $foreignKey, string $tableName): string
    {
        return 'ALTER TABLE ' . $tableName . ' ADD ' . $this->getForeignKeyDeclarationSQL($foreignKey);
    }

    public function getForeignKeyDeclarationSQL(ForeignKeyConstraint $foreignKey): string
    {
        return $this->getForeignKeyBaseDeclarationSQL($foreignKey);
    }

    public function getTruncateTableSQL(string $tableName, bool $cascade = false): string
    {
        return 'DELETE FROM ' . $this->quoteIdentifier($tableName) . ' WHERE true';
    }

    public function getIdentitySequenceName(string $tableName, string $columnName): string
    {
        return '';
    }

    public function supportsIdentityColumns(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $column */
    public function getIdentityColumnDeclarationSQL(array $column): string
    {
        return 'GENERATED BY DEFAULT AS IDENTITY (BIT_REVERSED_POSITIVE)';
    }

    public function supportsSequences(): bool
    {
        return true;
    }

    public function supportsForeignKeyConstraints(): bool
    {
        return true;
    }

    public function supportsForeignKeyConstraintsInline(): bool
    {
        return true;
    }

    public function supportsViews(): bool
    {
        return true;
    }

    public function getSetTransactionIsolationSQL(TransactionIsolationLevel $level): string
    {
        return '';
    }

    public function supportsTransactionIsolationLevel(TransactionIsolationLevel $level): bool
    {
        return in_array($level, [
            TransactionIsolationLevel::REPEATABLE_READ,
            TransactionIsolationLevel::SERIALIZABLE,
        ], true);
    }

    public function getReadLockSQL(): string
    {
        return '';
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new SpannerKeywords();
    }

    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        return new SpannerSchemaManager($connection, $this);
    }

    public function createMetadataProvider(Connection $connection): MetadataProvider
    {
        return new SpannerMetadataProvider($connection, $this);
    }

    public function getCreateSequenceSQL(Sequence $sequence): string
    {
        return 'CREATE SEQUENCE ' . $sequence->getQuotedName($this) .
               ' OPTIONS (sequence_kind = "bit_reversed_positive")';
    }

    public function getDropSequenceSQL(string $name): string
    {
        return 'DROP SEQUENCE ' . $name;
    }

    public function getListSequencesSQL(string $database): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.SEQUENCES';
    }

    public function getName(): string
    {
        return 'spanner';
    }

    public function getConcatExpression(string ...$string): string
    {
        return 'CONCAT(' . implode(', ', $string) . ')';
    }

    /** @param array<string, mixed> $column */
    public function getBinaryTypeDeclarationSQL(array $column): string
    {
        return 'BYTES(' . ($column['length'] ?? 'MAX') . ')';
    }

    public function getDateTimeFormatString(): string
    {
        return 'Y-m-d\TH:i:s.u\Z';
    }

    public function getDateTimeTzFormatString(): string
    {
        return 'Y-m-d\TH:i:s.uP';
    }

    public function getCurrentTimestampSQL(): string
    {
        return 'CURRENT_TIMESTAMP()';
    }

    public function getDummySelectSQL(string $expression = '1'): string
    {
        return 'SELECT ' . $expression;
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'bool'      => Types::BOOLEAN,
            'int64'     => Types::INTEGER,
            'float64'   => Types::FLOAT,
            'string'    => Types::STRING,
            'bytes'     => Types::BINARY,
            'date'      => Types::DATE_MUTABLE,
            'timestamp' => Types::DATETIME_MUTABLE,
            'numeric'   => Types::DECIMAL,
            'json'      => Types::JSON,
        ];
    }

    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if ($start !== null) {
            $substr = 'SUBSTR(' . $string . ', ' . $start . ')';

            return 'IF(STRPOS(' . $substr . ', ' . $substring . ') > 0, ' .
                'STRPOS(' . $substr . ', ' . $substring . ') + ' . $start . ' - 1, 0)';
        }

        return 'STRPOS(' . $string . ', ' . $substring . ')';
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return 'DATE_DIFF(CAST(' . $date1 . ' AS DATE), CAST(' . $date2 . ' AS DATE), DAY)';
    }

    public function getCurrentDatabaseExpression(): string
    {
        return "''";
    }

    public function getSubstringExpression(string $string, string $start, ?string $length = null): string
    {
        if ($length !== null) {
            return 'SUBSTR(' . $string . ', ' . $start . ', ' . $length . ')';
        }

        return 'SUBSTR(' . $string . ', ' . $start . ')';
    }

    public function quoteStringLiteral(string $str): string
    {
        return "'" . str_replace("'", "\'", $str) . "'";
    }

    public function quoteSingleIdentifier(string $str): string
    {
        return '`' . str_replace('`', '``', $str) . '`';
    }

    public function supportsSchemas(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Cloud Spanner requires a PRIMARY KEY for every table. If none is specified,
     * the implementation auto-selects the first column as the primary key to ensure
     * compatibility with generic DBAL schema definitions.
     *
     * @return list<string>
     */
    protected function _getCreateTableSQL(string $name, array $columns, array $options = []): array
    {
        $columnListSql = $this->getColumnDeclarationListSQL($columns);

        $sql = 'CREATE TABLE ' . $name . ' (' . $columnListSql . ')';

        if (! empty($options['primary'])) {
            $sql .= ' PRIMARY KEY (' . implode(', ', $options['primary']) . ')';
        } else {
            // Spanner requires a PRIMARY KEY for every table.
            // If none is specified (common in some DBAL tests), we use the first column.
            $firstColumn = reset($columns);
            if ($firstColumn !== false) {
                $sql .= ' PRIMARY KEY (' . $this->quoteIdentifier($firstColumn['name']) . ')';
            }
        }

        $sqls = [$sql];

        if (isset($options['indexes'])) {
            foreach ($options['indexes'] as $index) {
                $sqls[] = $this->getCreateIndexSQL($index, $name);
            }
        }

        if (isset($options['foreignKeys'])) {
            foreach ($options['foreignKeys'] as $foreignKey) {
                $sqls[] = $this->getCreateForeignKeySQL($foreignKey, $name);
            }
        }

        return $sqls;
    }

    /**
     * {@inheritDoc}
     *
     * @return list<string>
     *
     * @throws TypesException
     */
    public function getAlterSchemaSQL(SchemaDiff $diff): array
    {
        $sql = [];
        foreach ($diff->getCreatedTables() as $table) {
            foreach ($this->getCreateTableSQL($table) as $s) {
                $sql[] = $s;
            }
        }

        foreach ($diff->getDroppedTables() as $table) {
            $sql[] = $this->getDropTableSQL($table->getQuotedName($this));
        }

        foreach ($diff->getAlteredTables() as $tableDiff) {
            foreach ($this->getAlterTableSQL($tableDiff) as $s) {
                $sql[] = $s;
            }
        }

        return $sql;
    }

    public function getRenameTableSQL(string $oldName, string $newName): string
    {
        return 'RENAME TABLE ' . $this->quoteIdentifier($oldName) . ' TO ' . $this->quoteIdentifier($newName);
    }

    /** @return list<string> */
    public function getRenameColumnSQL(string $tableName, string $oldColumnName, string $newColumnName): array
    {
        // RENAME COLUMN is not supported in GoogleSQL.
        return [];
    }

    /**
     * @return list<string>
     *
     * @throws TypesException
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $tableName = $diff->getOldTable()->getQuotedName($this);
        $sql       = [];

        foreach ($diff->getAddedColumns() as $column) {
            $columnData = $column->toArray();
            if (! empty($columnData['notnull']) && ! isset($columnData['default'])) {
                $columnData['default'] = $this->getTypeSpecificDefaultValue($column->getType());
            }

            $sql[] = 'ALTER TABLE ' . $tableName . ' ADD COLUMN ' .
                $this->getColumnDeclarationSQL($column->getQuotedName($this), $columnData);
        }

        foreach ($diff->getDroppedColumns() as $column) {
            $sql[] = 'ALTER TABLE ' . $tableName . ' DROP COLUMN ' . $column->getQuotedName($this);
        }

        foreach ($diff->getChangedColumns() as $columnDiff) {
            $oldColumn = $columnDiff->getOldColumn();
            $newColumn = $columnDiff->getNewColumn();

            if ($columnDiff->hasNameChanged()) {
                $renameSql = $this->getRenameColumnSQL(
                    $diff->getOldTable()->getName(),
                    $oldColumn->getQuotedName($this),
                    $newColumn->getQuotedName($this),
                );
                foreach ($renameSql as $s) {
                    $sql[] = $s;
                }
            }

            if (! ($columnDiff->countChangedProperties() > ($columnDiff->hasNameChanged() ? 1 : 0))) {
                continue;
            }

            $typeDecl = $newColumn->getType()->getSQLDeclaration($newColumn->toArray(), $this);
            $notnull  = $newColumn->getNotnull() ? ' NOT NULL' : '';

            $sql[] = 'ALTER TABLE ' . $tableName . ' ALTER COLUMN ' .
                $newColumn->getQuotedName($this) . ' ' . $typeDecl . $notnull;

            if (! $columnDiff->hasDefaultChanged()) {
                continue;
            }

            $default = $this->getDefaultValueDeclarationSQL($newColumn->toArray());
            if ($default === '') {
                $sql[] = 'ALTER TABLE ' . $tableName . ' ALTER COLUMN ' .
                         $newColumn->getQuotedName($this) . ' SET DEFAULT (NULL)';
            } else {
                // Spanner syntax: ALTER COLUMN col SET DEFAULT (val)
                $sql[] = 'ALTER TABLE ' . $tableName . ' ALTER COLUMN ' .
                         $newColumn->getQuotedName($this) . ' SET' . $default;
            }
        }

        foreach ($diff->getDroppedIndexes() as $index) {
            $sql[] = $this->getDropIndexSQL($index->getQuotedName($this), $tableName);
        }

        foreach ($diff->getRenamedIndexes() as $oldName => $index) {
            $sql[] = $this->getDropIndexSQL($oldName, $tableName);
            $sql[] = $this->getCreateIndexSQL($index, $tableName);
        }

        foreach ($diff->getModifiedIndexes() as $index) {
            $sql[] = $this->getDropIndexSQL($index->getQuotedName($this), $tableName);
            $sql[] = $this->getCreateIndexSQL($index, $tableName);
        }

        foreach ($diff->getAddedIndexes() as $index) {
            $sql[] = $this->getCreateIndexSQL($index, $tableName);
        }

        foreach ($diff->getDroppedForeignKeys() as $foreignKey) {
            $sql[] = $this->getDropForeignKeySQL($foreignKey->getQuotedName($this), $tableName);
        }

        foreach ($diff->getAddedForeignKeys() as $foreignKey) {
            $sql[] = $this->getCreateForeignKeySQL($foreignKey, $tableName);
        }

        return $sql;
    }

    /** @return list<string> */
    public function getRenameIndexSQL(string $oldName, Index $index, string $tableName): array
    {
        return [];
    }

    /** @throws TypesException */
    private function getTypeSpecificDefaultValue(Type $type): mixed
    {
        return match (Type::lookupName($type)) {
            Types::INTEGER, Types::BIGINT, Types::SMALLINT => 0,
            Types::FLOAT, Types::DECIMAL => 0.0,
            Types::BOOLEAN => false,
            Types::STRING, Types::TEXT, Types::ASCII_STRING => '',
            Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE,
            Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE => '1970-01-01 00:00:00',
            Types::DATE_MUTABLE, Types::DATE_IMMUTABLE => '1970-01-01',
            default => null,
        };
    }

    /**
     * {@inheritDoc}
     *
     * Cloud Spanner does not support standard TIMESTAMP addition for some units (WEEK, MONTH, QUARTER, YEAR).
     * These are calculated via day-offsets based on DATE arithmetic to preserve the date part correctly.
     */
    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        DateIntervalUnit $unit,
    ): string {
        $unitStr = match ($unit) {
            DateIntervalUnit::SECOND => 'SECOND',
            DateIntervalUnit::MINUTE => 'MINUTE',
            DateIntervalUnit::HOUR => 'HOUR',
            DateIntervalUnit::DAY => 'DAY',
            DateIntervalUnit::WEEK => 'WEEK',
            DateIntervalUnit::MONTH => 'MONTH',
            DateIntervalUnit::QUARTER => 'QUARTER',
            DateIntervalUnit::YEAR => 'YEAR',
        };

        $largeUnits = [
            DateIntervalUnit::WEEK,
            DateIntervalUnit::MONTH,
            DateIntervalUnit::QUARTER,
            DateIntervalUnit::YEAR,
        ];
        if (in_array($unit, $largeUnits, true)) {
            $func = $operator === '+' ? 'DATE_ADD' : 'DATE_SUB';

            return 'TIMESTAMP_ADD(' . $date . ', INTERVAL DATE_DIFF(' .
                   $func . '(CAST(' . $date . ' AS DATE), INTERVAL ' . $interval . ' ' . $unitStr . '), ' .
                   'CAST(' . $date . ' AS DATE), DAY) DAY)';
        }

        $func = $operator === '+' ? 'TIMESTAMP_ADD' : 'TIMESTAMP_SUB';

        return $func . '(' . $date . ', INTERVAL ' . $interval . ' ' . $unitStr . ')';
    }

    /**
     * {@inheritDoc}
     *
     * Cloud Spanner requires column default values to be parenthesized: DEFAULT (val).
     */
    public function getDefaultValueDeclarationSQL(array $column): string
    {
        if (! isset($column['default'])) {
            return '';
        }

        $default = parent::getDefaultValueDeclarationSQL($column);
        if ($default === '') {
            return '';
        }

        $value = substr($default, 9);
        if (strpos(ltrim($value), '(') === 0) {
            return $default;
        }

        if (strpos($value, 'CURRENT_TIMESTAMP') !== false) {
            if (strpos($value, '(') === false) {
                $value = 'CURRENT_TIMESTAMP()';
            }
        }

        return ' DEFAULT (' . $value . ')';
    }

    /**
     * {@inheritDoc}
     *
     * Overridden to ensure NOT NULL precedes DEFAULT as required by Spanner.
     */
    public function getColumnDeclarationSQL(string $name, array $column): string
    {
        if (isset($column['columnDefinition'])) {
            return $name . ' ' . $column['columnDefinition'];
        }

        $typeDecl  = $column['type']->getSQLDeclaration($column, $this);
        $notnull   = ! empty($column['notnull']) ? ' NOT NULL' : '';
        $default   = $this->getDefaultValueDeclarationSQL($column);
        $collation = ! empty($column['collation']) ?
            ' COLLATE ' . $this->quoteSingleIdentifier($column['collation']) : '';

        $autoincrement = ! empty($column['autoincrement']) ? ' ' . $this->getIdentityColumnDeclarationSQL($column) : '';

        return $name . ' ' . $typeDecl . $notnull . $default . $collation . $autoincrement;
    }

    public function getTrimExpression(string $str, TrimMode $mode = TrimMode::UNSPECIFIED, ?string $char = null): string
    {
        if ($char === null) {
            return match ($mode) {
                TrimMode::LEADING => 'LTRIM(' . $str . ')',
                TrimMode::TRAILING => 'RTRIM(' . $str . ')',
                default => 'TRIM(' . $str . ')',
            };
        }

        return match ($mode) {
            TrimMode::LEADING => 'LTRIM(' . $str . ', ' . $char . ')',
            TrimMode::TRAILING => 'RTRIM(' . $str . ', ' . $char . ')',
            default => 'TRIM(' . $str . ', ' . $char . ')',
        };
    }

    public function supportsCommentOnCode(): bool
    {
        return false;
    }
}
