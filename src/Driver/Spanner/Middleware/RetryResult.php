<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner\Middleware;

use Doctrine\DBAL\Driver\Result;
use Throwable;

use function count;
use function usleep;

/**
 * Retriable result middleware for Cloud Spanner.
 *
 * Handles session loss during result set streaming by re-executing the statement.
 */
final class RetryResult implements Result
{
    private int $attempts = 0;

    private bool $hasStartedFetching = false;

    public function __construct(
        private Result $result,
        private readonly RetryStatement $statement,
        private readonly RetryConnection $connection,
    ) {
    }

    public function fetchNumeric(): array|false
    {
        return $this->retry(function () {
            $row = $this->result->fetchNumeric();
            if ($row !== false) {
                $this->hasStartedFetching = true;
            }

            return $row;
        });
    }

    public function fetchAssociative(): array|false
    {
        return $this->retry(function () {
            $row = $this->result->fetchAssociative();
            if ($row !== false) {
                $this->hasStartedFetching = true;
            }

            return $row;
        });
    }

    public function fetchOne(): mixed
    {
        return $this->retry(function () {
            $val = $this->result->fetchOne();
            if ($val !== false) {
                $this->hasStartedFetching = true;
            }

            return $val;
        });
    }

    /** @return list<list<mixed>> */
    public function fetchAllNumeric(): array
    {
        /** @var list<list<mixed>> $data */
        $data = $this->retry(function () {
            $res = $this->result->fetchAllNumeric();
            if (count($res) > 0) {
                $this->hasStartedFetching = true;
            }

            return $res;
        });

        return $data;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllAssociative(): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = $this->retry(function () {
            $res = $this->result->fetchAllAssociative();
            if (count($res) > 0) {
                $this->hasStartedFetching = true;
            }

            return $res;
        });

        return $data;
    }

    /** @return list<mixed> */
    public function fetchFirstColumn(): array
    {
        /** @var list<mixed> $data */
        $data = $this->retry(function () {
            $res = $this->result->fetchFirstColumn();
            if (count($res) > 0) {
                $this->hasStartedFetching = true;
            }

            return $res;
        });

        return $data;
    }

    public function rowCount(): int|string
    {
        return $this->result->rowCount();
    }

    public function columnCount(): int
    {
        return $this->result->columnCount();
    }

    public function free(): void
    {
        $this->result->free();
    }

    /** @internal */
    public function updateResult(Result $result): void
    {
        $this->result = $result;
    }

    /**
     * @param callable(): T $operation
     *
     * @return T
     *
     * @template T
     */
    private function retry(callable $operation): mixed
    {
        while (true) {
            try {
                return $operation();
            } catch (Throwable $e) {
                if ($this->hasStartedFetching) {
                    throw $e;
                }

                $isSessionError = $this->connection->isSessionError($e);

                if ($this->attempts >= 10 || ! $isSessionError) {
                    throw $e;
                }

                $this->attempts++;
                try {
                    $this->refreshAndReexecute();
                } catch (Throwable) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Refreshes the connection and re-executes the original statement.
     */
    private function refreshAndReexecute(): void
    {
        $reexecuteAttempts = 0;
        while (true) {
            try {
                $reexecuteAttempts++;
                $this->connection->refresh();
                usleep(500 * 1000 * $this->attempts);
                $this->updateResult($this->statement->reexecute());

                return;
            } catch (Throwable $e) {
                $isSessionError = $this->connection->isSessionError($e);

                if ($reexecuteAttempts >= 5 || ! $isSessionError) {
                    throw $e;
                }
            }
        }
    }
}
