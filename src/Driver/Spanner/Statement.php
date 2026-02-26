<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\Transaction;
use Throwable;

use function assert;
use function is_int;
use function ltrim;
use function preg_match;

/**
 * Cloud Spanner statement.
 */
class Statement implements StatementInterface
{
    /** @var array<string|int, mixed> */
    private array $params = [];

    /** @var array<string|int, ParameterType> */
    private array $types = [];

    public function __construct(
        private readonly Connection $connection,
        private string $sql,
    ) {
    }

    public function bindValue(string|int $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        $this->params[$param] = $value;
        $this->types[$param]  = $type;
    }

    /**
     * {@inheritDoc}
     *
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws \Doctrine\DBAL\SQL\Parser\Exception
     */
    public function execute(): DriverResult
    {
        $database      = $this->connection->getNativeConnection();
        $spannerParams = [];

        foreach ($this->params as $param => $value) {
            $name = is_int($param) ? 'param' . $param : ltrim($param, ':');

            $type = $this->types[$param] ?? ParameterType::STRING;

            if ($value === null) {
                $spannerParams[$name] = null;
                continue;
            }

            switch ($type) {
                case ParameterType::BOOLEAN:
                    $spannerParams[$name] = (bool) $value;
                    break;

                case ParameterType::INTEGER:
                    assert(is_int($value));
                    $spannerParams[$name] = $value;
                    break;

                case ParameterType::BINARY:
                case ParameterType::LARGE_OBJECT:
                    $spannerParams[$name] = $value;
                    break;

                default:
                    if ($value instanceof DateTimeInterface) {
                        // DBAL expects the driver to handle the timezone.
                        // We convert any given DateTime to UTC before sending to Spanner.
                        if ($value instanceof DateTimeImmutable) {
                            $utcValue = $value->setTimezone(new DateTimeZone('UTC'));
                        } else {
                            $utcValue = clone $value;
                            $utcValue->setTimezone(new DateTimeZone('UTC'));
                        }

                        $spannerParams[$name] = new Timestamp($utcValue);
                    } else {
                        $spannerParams[$name] = $value;
                    }
            }
        }

        // Map placeholders to @ syntax for Spanner.
        $converter = new SQL\PlaceholderConverter();
        $sql       = $converter->convert($this->sql);

        try {
            // Check if we are in a DML-like statement.
            $isDml = (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE|MERGE)\b/i', $sql);

            if ($isDml) {
                $activeTransaction = $this->connection->getActiveTransaction();
                if ($activeTransaction !== null) {
                    $res = $activeTransaction->getTransaction()->executeUpdate($sql, ['parameters' => $spannerParams]);

                    return new Result(null, $res);
                }

                $res = $database->runTransaction(static function (object $t) use ($sql, $spannerParams) {
                    /** @var Transaction $t */
                    $res = $t->executeUpdate($sql, ['parameters' => $spannerParams]);
                    $t->commit();

                    return $res;
                });

                assert(is_int($res));

                return new Result(null, $res);
            }

            $activeTransaction = $this->connection->getActiveTransaction();
            if ($activeTransaction !== null) {
                $res = $activeTransaction->getTransaction()->execute($sql, ['parameters' => $spannerParams]);

                return new Result($res);
            }

            $res = $database->execute($sql, ['parameters' => $spannerParams]);

            return new Result($res);
        } catch (Throwable $e) {
            throw Exception::fromThrowable($e);
        }
    }
}
