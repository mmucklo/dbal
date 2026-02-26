<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner\Middleware;

use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Spanner\Connection as SpannerConnection;
use Doctrine\DBAL\Driver\Spanner\NativeSpannerProvider;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception\ConnectionException;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\SpannerClient;
use Throwable;

use function str_contains;
use function strtolower;
use function strtoupper;
use function usleep;

/**
 * Retriable connection middleware for Cloud Spanner.
 *
 * Provides aggressive retry logic for session-related errors and emulator instability.
 */
final class RetryConnection extends AbstractConnectionMiddleware implements NativeSpannerProvider
{
    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
    public function __construct(private readonly SpannerConnection $connection)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new RetryStatement(parent::prepare($sql), $this, $sql);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function query(string $sql): Result
    {
        // We rely on RetryStatement's retry loop via prepare()->execute().
        return $this->prepare($sql)->execute();
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function exec(string $sql): int|string
    {
        $attempts = 0;
        while (true) {
            try {
                return parent::exec($sql);
            } catch (Throwable $e) {
                $attempts++;
                $isSessionError = $this->isSessionError($e);

                if ($attempts < 50 && $isSessionError) {
                    $this->refresh();
                    // Longer sleep for emulator stability.
                    usleep(500 * 1000 * $attempts);

                    continue;
                }

                // Handling for "already exists" errors during retries of CREATE/RENAME statements.
                if (
                    $attempts > 1 && (str_contains(strtoupper($sql), 'CREATE') ||
                    str_contains(strtoupper($sql), 'RENAME')) &&
                    (str_contains($e->getMessage(), 'already exists') ||
                    str_contains($e->getMessage(), 'Duplicate name'))
                ) {
                    return 0;
                }

                throw $e;
            }
        }
    }

    /**
     * Executes a batch of DDL statements with retries.
     *
     * @internal
     *
     * @param list<string> $statements
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function executeDdlBatch(array $statements): void
    {
        $attempts = 0;
        while (true) {
            try {
                $this->connection->executeDdlBatch($statements);

                return;
            } catch (Throwable $e) {
                $attempts++;

                if ($this->isDescriptorError($e)) {
                    $this->deepRefresh();
                    usleep(500 * 1000 * $attempts);

                    continue;
                }

                if ($attempts < 10 && $this->isSessionError($e)) {
                    $this->refresh();
                    usleep(500 * 1000 * $attempts);

                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Refreshes the underlying Spanner connection.
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function refresh(): void
    {
        $this->connection->connect();
    }

    /**
     * Performs a deep refresh by re-instantiating the SpannerClient.
     *
     * @internal
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function deepRefresh(): void
    {
        $this->connection->deepRefresh();
    }

    /**
     * Detects errors that are likely transient or session-related and should be retried.
     */
    public function isSessionError(Throwable $e): bool
    {
        $current = $e;
        while ($current !== null) {
            if ($current instanceof ConnectionException) {
                return true;
            }

            $msg = strtolower($current->getMessage());

            $isSessionNotFound = str_contains($msg, 'session not found') ||
                                 str_contains($msg, 'session does not exist') ||
                                 (str_contains($msg, 'not_found') && str_contains($msg, 'session'));

            if (
                $isSessionNotFound ||
                str_contains($msg, 'transport error') ||
                str_contains($msg, 'specified message in any') ||
                str_contains($msg, 'concurrent schema change operation') ||
                str_contains($msg, 'aborted due to active transaction') ||
                str_contains($msg, 'type url needs to be') ||
                str_contains($msg, 'error parsing json')
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * Detects Protobuf descriptor pool errors which are often fatal in the emulator.
     */
    public function isDescriptorError(Throwable $e): bool
    {
        $current = $e;
        while ($current !== null) {
            $msg = strtolower($current->getMessage());

            if (
                str_contains($msg, 'zetasql') ||
                str_contains($msg, 'descriptor pool') ||
                str_contains($msg, "hasn't been added")
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    public function getNativeConnection(): Database
    {
        return $this->connection->getNativeConnection();
    }

    public function getSpannerClient(): SpannerClient
    {
        return $this->connection->getSpannerClient();
    }

    public function getSpannerInstanceId(): string
    {
        return $this->connection->getSpannerInstanceId();
    }

    public function getSpannerDatabase(): Database
    {
        return $this->connection->getSpannerDatabase();
    }

    public function isEmulator(): bool
    {
        return $this->connection->isEmulator();
    }

    /** @internal */
    public function getWrappedConnection(): SpannerConnection
    {
        return $this->connection;
    }
}
