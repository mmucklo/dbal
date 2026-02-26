<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware;

final class RetryMiddleware implements Middleware
{
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new RetryDriver($driver);
    }
}
