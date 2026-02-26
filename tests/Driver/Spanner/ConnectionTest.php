<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Driver\Spanner;

use Doctrine\DBAL\Driver\Spanner\Driver;
use Doctrine\DBAL\Driver\Spanner\Middleware\RetryConnection;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\SpannerClient;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function class_exists;

#[RequiresPhpExtension('grpc')]
class ConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(SpannerClient::class)) {
            self::markTestSkipped('Google Cloud Spanner SDK is not installed.');
        }
    }

    public function testGetNativeConnection(): void
    {
        $driver = new Driver();
        // Spanner client doesn't connect in constructor, so we can pass dummy params if no network call is made.
        $params = [
            'projectId' => 'test-project',
            'instanceId' => 'test-instance',
            'database' => 'test-database',
        ];

        $connection = $driver->connect($params);
        $this->assertInstanceOf(RetryConnection::class, $connection);

        $native = $connection->getNativeConnection();
        $this->assertInstanceOf(Database::class, $native);
    }
}
