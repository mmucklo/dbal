<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Retriable driver middleware for Cloud Spanner.
 */
final class RetryDriver extends AbstractDriverMiddleware
{
}
