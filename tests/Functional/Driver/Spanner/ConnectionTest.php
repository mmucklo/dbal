<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Driver\Spanner;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tests\FunctionalTestCase;

class ConnectionTest extends FunctionalTestCase
{
    public function testFetchOne(): void
    {
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
    }

    public function testCreateTable(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $table         = new Table('functional_test');
        $table->addColumn('id', 'integer');
        $table->addColumn('name', 'string', ['length' => 255]);
        $table->setPrimaryKey(['id']);

        $this->dropTableIfExists('functional_test');
        $schemaManager->createTable($table);

        $this->connection->insert(
            'functional_test',
            ['id' => 1, 'name' => 'test'],
            ['id' => ParameterType::INTEGER],
        );
        self::assertSame('test', $this->connection->fetchOne('SELECT name FROM functional_test WHERE id = 1'));
    }
}
