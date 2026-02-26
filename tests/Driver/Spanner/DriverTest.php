<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Driver\Spanner;

use Doctrine\DBAL\Driver\Spanner\Driver;
use Doctrine\DBAL\Driver\Spanner\ExceptionConverter;
use Doctrine\DBAL\Platforms\SpannerPlatform;
use Doctrine\DBAL\Platforms\SpannerPostgreSQLPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Google\Cloud\Spanner\SpannerClient;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function class_exists;

#[RequiresPhpExtension('grpc')]
class DriverTest extends TestCase
{
    private Driver $driver;

    protected function setUp(): void
    {
        if (! class_exists(SpannerClient::class)) {
            self::markTestSkipped('Google Cloud Spanner SDK is not installed.');
        }

        $this->driver = new Driver();
    }

    public function testGetExceptionConverter(): void
    {
        $this->assertInstanceOf(ExceptionConverter::class, $this->driver->getExceptionConverter());
    }

    public function testGetDatabasePlatformGoogleSQL(): void
    {
        $versionProvider = $this->createMock(ServerVersionProvider::class);
        $versionProvider->method('getServerVersion')->willReturn('GoogleSQL 1.0 (Spanner)');

        $this->assertInstanceOf(SpannerPlatform::class, $this->driver->getDatabasePlatform($versionProvider));
    }

    public function testGetDatabasePlatformPostgreSQL(): void
    {
        $versionProvider = $this->createMock(ServerVersionProvider::class);
        $versionProvider->method('getServerVersion')->willReturn('PostgreSQL 14.0 (Spanner)');

        $this->assertInstanceOf(SpannerPostgreSQLPlatform::class, $this->driver->getDatabasePlatform($versionProvider));
    }
}
