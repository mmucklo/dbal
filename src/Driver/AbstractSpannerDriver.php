<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Spanner\ExceptionConverter as SpannerExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SpannerPlatform;
use Doctrine\DBAL\Platforms\SpannerPostgreSQLPlatform;
use Doctrine\DBAL\ServerVersionProvider;

use function str_contains;

/**
 * Abstract base class for Google Cloud Spanner drivers.
 */
abstract class AbstractSpannerDriver implements Driver
{
    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        $version = $versionProvider->getServerVersion();
        if (str_contains($version, 'PostgreSQL')) {
             return new SpannerPostgreSQLPlatform();
        }

        return new SpannerPlatform();
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new SpannerExceptionConverter();
    }
}
