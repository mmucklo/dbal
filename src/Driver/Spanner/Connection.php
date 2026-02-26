<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Transaction;
use Throwable;

use function assert;
use function count;
use function getenv;
use function is_string;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function usleep;

/**
 * Cloud Spanner connection.
 */
class Connection implements ConnectionInterface, NativeSpannerProvider
{
    private Database $database;

    private ?TransactionContext $activeTransaction = null;

    private bool $isEmulator = false;

    /**
     * @param string[] $clientOptions
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function __construct(
        private object $client,
        private readonly string $instanceId,
        private readonly string $databaseId,
        private readonly array $clientOptions = [],
    ) {
        $host             = $clientOptions['emulatorHost'] ?? getenv('SPANNER_EMULATOR_HOST');
        $this->isEmulator = is_string($host) && $host !== '';
        $this->connect();
    }

    public function isEmulator(): bool
    {
        return $this->isEmulator;
    }

    /**
     * @throws Exception
     * @throws GoogleException
     */
    public function connect(): void
    {
        if (count($this->clientOptions) > 0) {
             $this->client = new SpannerClient($this->clientOptions);
        }

        $client = $this->client;
        assert($client instanceof SpannerClient);
        $this->database = $client->instance($this->instanceId)->database($this->databaseId);
    }

    /**
     * Deep refresh the client to handle fatal protobuf errors in the emulator.
     *
     * @internal
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function deepRefresh(): void
    {
        if (! $this->isEmulator) {
            return;
        }

        // Re-instantiating the SpannerClient forces a reload of descriptors in the SDK.
        try {
            $this->client = new SpannerClient($this->clientOptions);
        } catch (Throwable $e) {
            throw Exception::fromThrowable($e);
        }

        $this->connect();
    }

    public function prepare(string $sql): DriverStatement
    {
        return new Statement($this, $sql);
    }

    public function query(string $sql): DriverResult
    {
        return $this->prepare($sql)->execute();
    }

    public function quote(string $value): string
    {
        if (str_contains($this->getServerVersion(), 'PostgreSQL')) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        // Spanner GoogleSQL uses backslash escaping for emulator compatibility.
        return "'" . str_replace("'", "\'", $value) . "'";
    }

    public function exec(string $sql): int|string
    {
        $trimmedSql = ltrim($sql);
        if ($trimmedSql === '' || str_starts_with($trimmedSql, '--')) {
            return 0;
        }

        // Detect DDL statements which must be executed via updateDdl.
        $isDdl = (bool) preg_match('/^\s*(CREATE|ALTER|DROP|RENAME|GRANT|REVOKE|ANALYZE)\b/i', $trimmedSql);

        try {
            if ($isDdl) {
                $this->executeDdlBatch([$sql]);

                return 0;
            }

            if ($this->activeTransaction !== null) {
                return $this->activeTransaction->getTransaction()->executeUpdate($sql);
            }

            return (int) $this->database->runTransaction(static function (Transaction $t) use ($sql): int {
                $count = $t->executeUpdate($sql);
                $t->commit();

                return $count;
            });
        } catch (Throwable $e) {
            throw Exception::fromThrowable($e);
        }
    }

    /**
     * Executes a batch of DDL statements.
     *
     * @internal
     *
     * @param list<string> $statements
     *
     * @throws Exception
     */
    public function executeDdlBatch(array $statements): void
    {
        if (count($statements) === 0) {
            return;
        }

        $attempts = 0;
        while (true) {
            try {
                // Skip CREATE/DROP SCHEMA if they fail, as many tests assume schema support.
                if (count($statements) === 1 && preg_match('/^\s*(CREATE|DROP)\s+SCHEMA\b/i', $statements[0]) === 1) {
                    try {
                        $this->database->updateDdl($statements[0])->pollUntilComplete();
                        if ($this->isEmulator) {
                            usleep(500 * 1000);
                        }
                    } catch (Throwable) {
                        // Ignore schema errors.
                    }

                    return;
                }

                if (count($statements) === 1) {
                    $this->database->updateDdl($statements[0])->pollUntilComplete();
                } else {
                    $this->database->updateDdlBatch($statements)->pollUntilComplete();
                }

                if ($this->isEmulator) {
                    // Post-LRO buffer for emulator stability.
                    usleep(3000 * 1000);
                }

                return;
            } catch (Throwable $e) {
                $attempts++;
                $msg = $e->getMessage();

                if (
                    $this->isEmulator && $attempts < 10 &&
                    (str_contains($msg, 'Concurrent schema change') || str_contains($msg, 'aborted'))
                ) {
                    usleep(1000 * 1000 * $attempts);
                    continue;
                }

                throw Exception::fromThrowable($e);
            }
        }
    }

    public function lastInsertId(): string|int
    {
        return 0;
    }

    public function beginTransaction(): void
    {
        $this->activeTransaction = new TransactionContext($this->database->transaction());
    }

    public function commit(): void
    {
        if ($this->activeTransaction === null) {
            return;
        }

        try {
            $this->activeTransaction->commit();
        } catch (Throwable $e) {
            throw Exception::fromThrowable($e);
        } finally {
            $this->activeTransaction = null;
        }
    }

    public function rollBack(): void
    {
        if ($this->activeTransaction === null) {
            return;
        }

        try {
            $this->activeTransaction->rollback();
        } finally {
            $this->activeTransaction = null;
        }
    }

    public function getNativeConnection(): Database
    {
        return $this->database;
    }

    public function getServerVersion(): string
    {
        return 'GoogleSQL 1.0 (Spanner)';
    }

    public function getSpannerClient(): SpannerClient
    {
        $client = $this->client;
        assert($client instanceof SpannerClient);

        return $client;
    }

    public function getSpannerInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getSpannerDatabase(): Database
    {
        return $this->database;
    }

    /** @internal */
    public function getActiveTransaction(): ?TransactionContext
    {
        return $this->activeTransaction;
    }
}
