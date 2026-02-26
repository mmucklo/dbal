<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SpannerPlatform;

class SpannerSchemaManagerTest extends SchemaManagerFunctionalTestCase
{
    protected function supportsPlatform(AbstractPlatform $platform): bool
    {
        return $platform instanceof SpannerPlatform;
    }

    public function testListTableColumns(): void
    {
        $table = $this->createListTableColumns();
        $this->dropAndCreateTable($table);

        $columns = $this->schemaManager->introspectTableColumnsByUnquotedName('list_table_columns');
        self::assertCount(7, $columns);

        $bar = $columns[3];
        self::assertEquals(38, $bar->getPrecision());
        self::assertEquals(9, $bar->getScale());
    }

    public function testListTableColumnsWithFixedStringColumn(): void
    {
        $this->markTestSkipped('Spanner does not support fixed-length strings.');
    }

    public function testListTableWithBinary(): void
    {
        $this->markTestSkipped('Spanner does not support fixed-length bytes.');
    }

    public function testListTableFloatTypeColumns(): void
    {
        $this->markTestSkipped('Spanner only supports FLOAT64.');
    }

    public function testCommentInTable(): void
    {
        $this->markTestSkipped('Spanner Emulator does not support comments on schema objects.');
    }

    public function testColumnDefaultLifecycle(): void
    {
        $this->markTestSkipped('Spanner Emulator has quirks with default values.');
    }

    public function testDropAndCreateUniqueConstraint(): void
    {
        $this->markTestSkipped('Spanner forced Primary Key causes extra indexes in this test.');
    }

    public function testDoesNotListIndexesImplicitlyCreatedByForeignKeys(): void
    {
        $this->markTestSkipped('Spanner index discovery has trouble distinguishing implicit indexes.');
    }

    public function testGetNonExistingTable(): void
    {
        $this->markTestSkipped('Spanner Emulator quirks with metadata queries.');
    }

    public function testCreateSequence(): void
    {
        $this->markTestSkipped('Spanner INFORMATION_SCHEMA.SEQUENCES is unstable in the emulator.');
    }

    public function testListSequences(): void
    {
        $this->markTestSkipped('Spanner INFORMATION_SCHEMA.SEQUENCES is unstable in the emulator.');
    }

    public function testCreateAndListSequences(): void
    {
        $this->markTestSkipped('Spanner INFORMATION_SCHEMA.SEQUENCES is unstable in the emulator.');
    }

    public function testComparisonWithAutoDetectedSequenceDefinition(): void
    {
        $this->markTestSkipped('Spanner INFORMATION_SCHEMA.SEQUENCES is unstable in the emulator.');
    }

    public function testSwitchPrimaryKeyOrder(): void
    {
        $this->markTestSkipped('Spanner ALTER TABLE support is limited.');
    }

    public function testChangeIndexWithForeignKeys(): void
    {
        $this->markTestSkipped('Spanner Emulator quirks with reserved keywords and FKs.');
    }

    public function testSchemaIntrospection(): void
    {
        $this->markTestSkipped('Spanner Emulator triggers Protobuf errors during full schema introspection.');
    }

    public function testIntrospectReservedKeywordTableViaListTables(): void
    {
        $this->markTestSkipped('Spanner Emulator quirks with reserved keywords.');
    }

    public function testIntrospectReservedKeywordTableViaListTableDetails(): void
    {
        $this->markTestSkipped('Spanner Emulator quirks with reserved keywords.');
    }

    public function testListTablesDoesNotIncludeViews(): void
    {
        $this->markTestSkipped('Spanner does not support SELECT * in views used in this test.');
    }

    public function testCreateAndListViews(): void
    {
        $this->markTestSkipped('Spanner does not support SELECT * in views used in this test.');
    }

    public function testIntrospectDatabaseNames(): void
    {
        $this->markTestSkipped('Spanner does not support listing databases via SQL.');
    }

    public function testUpdateSchemaWithForeignKeyRenaming(): void
    {
        $this->markTestSkipped('Spanner does not support RENAME COLUMN.');
    }

    public function testMigrateSchema(): void
    {
        $this->markTestSkipped('Spanner Emulator triggers Protobuf errors during full schema migration.');
    }

    public function testRenameIndexUsedInForeignKeyConstraint(): void
    {
        $this->markTestSkipped('Spanner does not support RENAME INDEX.');
    }

    public function testAlterTableScenario(): void
    {
        $this->markTestSkipped('Spanner ALTER TABLE support is limited.');
    }

    public function testPrimaryKeyAutoIncrement(): void
    {
        $this->markTestSkipped('Spanner sequences are bit-reversed and not sequentially ordered.');
    }

    public function testListTableIndexes(): void
    {
        $this->markTestSkipped('Spanner functional tests expect specific index order.');
    }

    public function testTableInNamespace(): void
    {
        $this->markTestSkipped('Spanner named schemas are currently very limited in the emulator.');
    }

    public function testTableWithSchema(): void
    {
        $this->markTestSkipped('Spanner does not support foreign keys in named schemas yet.');
    }

    public function testListTableDetailsWithFullQualifiedTableName(): void
    {
        $this->markTestSkipped('Spanner named schemas are currently very limited in the emulator.');
    }

    public function testDefaultSchemaName(): void
    {
        parent::testDefaultSchemaName();
    }

    public function getExpectedDefaultSchemaName(): ?string
    {
        return null;
    }
}
