<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner;

use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Spanner\Transaction;

/**
 * Encapsulates an active Spanner transaction.
 *
 * @internal
 */
final class TransactionContext
{
    public function __construct(private readonly Transaction $transaction)
    {
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    /** @throws AbortedException */
    public function commit(): void
    {
        $this->transaction->commit();
    }

    public function rollback(): void
    {
        $this->transaction->rollback();
    }
}
