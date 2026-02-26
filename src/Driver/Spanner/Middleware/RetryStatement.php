<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner\Middleware;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Exception;
use Google\Cloud\Core\Exception\GoogleException;
use Throwable;

use function usleep;

/**
 * Retriable statement middleware for Cloud Spanner.
 */
final class RetryStatement extends AbstractStatementMiddleware
{
    private Statement $innerStatement;

    public function __construct(
        Statement $wrappedStatement,
        private readonly RetryConnection $connection,
        private readonly string $sql,
    ) {
        parent::__construct($wrappedStatement);

        $this->innerStatement = $wrappedStatement;
    }

    /**
     * {@inheritDoc}
     *
     * @throws DriverException
     * @throws Exception
     * @throws GoogleException
     */
    public function execute(): Result
    {
        $attempts = 0;
        while (true) {
            try {
                return new RetryResult($this->innerStatement->execute(), $this, $this->connection);
            } catch (Throwable $e) {
                $attempts++;

                if ($attempts < 50 && $this->connection->isDescriptorError($e)) {
                    $this->connection->deepRefresh();
                    // Re-prepare the statement on the new session.
                    $this->updateWrappedStatement($this->connection->getWrappedConnection()->prepare($this->sql));
                    usleep(500 * 1000 * $attempts);

                    continue;
                }

                if ($attempts < 50 && $this->connection->isSessionError($e)) {
                    $this->connection->refresh();
                    // Re-prepare the statement on the new session.
                    $this->updateWrappedStatement($this->connection->getWrappedConnection()->prepare($this->sql));
                    usleep(200 * 1000 * $attempts);

                    continue;
                }

                throw $e;
            }
        }
    }

    /** @internal */
    public function updateWrappedStatement(Statement $statement): void
    {
        $this->innerStatement = $statement;
    }

    /**
     * Re-executes the statement.
     *
     * @internal
     *
     * @throws DriverException
     * @throws Exception
     */
    public function reexecute(): Result
    {
        return $this->innerStatement->execute();
    }
}
