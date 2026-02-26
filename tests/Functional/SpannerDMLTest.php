<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional;

use Doctrine\DBAL\Platforms\SpannerPlatform;

class SpannerDMLTest extends DataAccessTest
{
    protected function setUp(): void
    {
        if (!extension_loaded('grpc')) {
            $this->markTestSkipped('The grpc extension is required for this test.');
        }
        if (!$this->connection->getDatabasePlatform() instanceof SpannerPlatform) {
            $this->markTestSkipped('Spanner only test.');
        }
        parent::setUp();
    }

    public function testSpannerUpdate(): void
    {
        $this->connection->update('fetch_table', ['test_string' => 'bar'], ['test_int' => 1]);
        $val = $this->connection->fetchOne('SELECT test_string FROM fetch_table WHERE test_int = 1');
        self::assertEquals('bar', $val);
    }

    public function testSpannerDelete(): void
    {
        $this->connection->delete('fetch_table', ['test_int' => 1]);
        $val = $this->connection->fetchOne('SELECT COUNT(*) FROM fetch_table');
        self::assertEquals(0, $val);
    }
}
