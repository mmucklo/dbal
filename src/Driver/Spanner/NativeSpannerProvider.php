<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner;

use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\SpannerClient;

/**
 * Interface for components that provide access to native Spanner objects.
 *
 * @internal
 */
interface NativeSpannerProvider
{
    public function getSpannerClient(): SpannerClient;

    public function getSpannerDatabase(): Database;

    public function getSpannerInstanceId(): string;

    public function isEmulator(): bool;
}
