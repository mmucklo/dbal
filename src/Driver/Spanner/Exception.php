<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner;

use Doctrine\DBAL\Driver\AbstractException;
use Throwable;

/** @internal */
final class Exception extends AbstractException
{
    public static function fromThrowable(Throwable $e): self
    {
        return new self($e->getMessage(), null, (int) $e->getCode(), $e);
    }
}
