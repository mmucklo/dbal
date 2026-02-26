<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\Spanner;

/**
 * Helper class to stitch together metadata from separate INFORMATION_SCHEMA queries.
 *
 * @internal
 */
final class MetadataAggregator
{
    /**
     * @param list<array<string, mixed>> $indexes
     * @param list<array<string, mixed>> $indexColumns
     *
     * @return array<string, array<string, array{is_unique: bool, columns: list<array<string, mixed>>}>>
     */
    public static function aggregateIndexes(array $indexes, array $indexColumns): array
    {
        $uniqueMap = [];
        foreach ($indexes as $idx) {
            $tableName                         = (string) ($idx['TABLE_NAME'] ?? '');
            $indexName                         = (string) ($idx['INDEX_NAME'] ?? '');
            $uniqueMap[$tableName][$indexName] = (bool) ($idx['IS_UNIQUE'] ?? false);
        }

        $result = [];
        foreach ($indexColumns as $col) {
            $tableName = (string) ($col['TABLE_NAME'] ?? '');
            $indexName = (string) ($col['INDEX_NAME'] ?? '');

            if ($indexName === 'PRIMARY_KEY') {
                continue;
            }

            if (! isset($result[$tableName][$indexName])) {
                $result[$tableName][$indexName] = [
                    'is_unique' => $uniqueMap[$tableName][$indexName] ?? false,
                    'columns'   => [],
                ];
            }

            $result[$tableName][$indexName]['columns'][] = $col;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $keyColumnUsage
     * @param list<array<string, mixed>> $referentialConstraints
     * @param list<array<string, mixed>> $referencedKeyColumnUsage
     *
     * @return array<string, array<string, array{columns: list<string>, referenced_table: string, referenced_columns: list<string>}>>
     */
    public static function aggregateForeignKeys(
        array $keyColumnUsage,
        array $referentialConstraints,
        array $referencedKeyColumnUsage,
    ): array {
        $rcMap = [];
        foreach ($referentialConstraints as $rc) {
            $rcMap[(string) $rc['CONSTRAINT_NAME']] = (string) $rc['UNIQUE_CONSTRAINT_NAME'];
        }

        $refMap = [];
        foreach ($referencedKeyColumnUsage as $k2) {
            $constraintName                    = (string) $k2['CONSTRAINT_NAME'];
            $ordinal                           = (int) $k2['ORDINAL_POSITION'];
            $refMap[$constraintName][$ordinal] = [
                'table'  => (string) $k2['TABLE_NAME'],
                'column' => (string) $k2['COLUMN_NAME'],
            ];
        }

        $result = [];
        foreach ($keyColumnUsage as $k) {
            $tableName      = (string) $k['TABLE_NAME'];
            $constraintName = (string) $k['CONSTRAINT_NAME'];

            if (! isset($rcMap[$constraintName])) {
                continue;
            }

            $uniqueConstraintName = $rcMap[$constraintName];
            $ordinal              = (int) $k['ORDINAL_POSITION'];

            if (! isset($refMap[$uniqueConstraintName][$ordinal])) {
                continue;
            }

            $ref = $refMap[$uniqueConstraintName][$ordinal];

            if (! isset($result[$tableName][$constraintName])) {
                $result[$tableName][$constraintName] = [
                    'columns'            => [],
                    'referenced_table'   => $ref['table'],
                    'referenced_columns' => [],
                ];
            }

            $result[$tableName][$constraintName]['columns'][]            = (string) $k['COLUMN_NAME'];
            $result[$tableName][$constraintName]['referenced_columns'][] = $ref['column'];
        }

        return $result;
    }
}
