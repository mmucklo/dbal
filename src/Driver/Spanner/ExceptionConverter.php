<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DatabaseObjectNotFoundException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query;

use function str_contains;

final class ExceptionConverter implements ExceptionConverterInterface
{
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        $code = $exception->getCode();

        switch ($code) {
            case 10: // ABORTED
                return new DeadlockException($exception, $query);

            case 6: // ALREADY_EXISTS
                if (
                    str_contains($exception->getMessage(), 'Table already exists') ||
                    str_contains($exception->getMessage(), 'Duplicate name in schema')
                ) {
                    return new TableExistsException($exception, $query);
                }

                return new UniqueConstraintViolationException($exception, $query);

            case 5: // NOT_FOUND
                if (str_contains($exception->getMessage(), 'Table not found')) {
                    return new TableNotFoundException($exception, $query);
                }

                if (str_contains($exception->getMessage(), 'Session not found')) {
                    return new ConnectionException($exception, $query);
                }

                return new DatabaseObjectNotFoundException($exception, $query);

            case 3: // INVALID_ARGUMENT
                return new SyntaxErrorException($exception, $query);

            case 9: // FAILED_PRECONDITION
                if (str_contains($exception->getMessage(), 'Duplicate name in schema')) {
                    return new TableExistsException($exception, $query);
                }

                return new ForeignKeyConstraintViolationException($exception, $query);

            case 14: // UNAVAILABLE
                return new ConnectionException($exception, $query);

            default:
                if (
                    str_contains($exception->getMessage(), 'class zetasql') ||
                    str_contains($exception->getMessage(), 'descriptor pool') ||
                    str_contains($exception->getMessage(), "any hasn't been added")
                ) {
                    return new ConnectionException($exception, $query);
                }
        }

        return new DriverException($exception, $query);
    }
}
