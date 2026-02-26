<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Google\Cloud\Spanner\Date;
use Google\Cloud\Spanner\Result as SpannerResult;
use Google\Cloud\Spanner\Timestamp;
use Iterator;

use function array_values;
use function assert;
use function count;

/**
 * Google Cloud Spanner driver result.
 */
final class Result implements ResultInterface
{
    /** @var Iterator<int, array<string, mixed>>|null */
    private ?Iterator $iterator = null;

    public function __construct(private ?object $spannerResult, private readonly int $rowCount = 0)
    {
    }

    private function initialize(): void
    {
        if ($this->iterator !== null || $this->spannerResult === null) {
            return;
        }

        $res = $this->spannerResult;
        assert($res instanceof SpannerResult);
        $this->iterator = $res->rows();
        $this->iterator->rewind();
    }

    public function fetchNumeric(): array|false
    {
        $this->initialize();

        if ($this->iterator === null || ! $this->iterator->valid()) {
            return false;
        }

        /** @var array<string, mixed> $row */
        $row = $this->iterator->current();
        $this->iterator->next();

        return array_values($this->convertRow($row));
    }

    public function fetchAssociative(): array|false
    {
        $this->initialize();

        if ($this->iterator === null || ! $this->iterator->valid()) {
            return false;
        }

        /** @var array<string, mixed> $row */
        $row = $this->iterator->current();
        $this->iterator->next();

        return $this->convertRow($row);
    }

    /**
     * Converts Spanner-specific types (Timestamp, Date) to standard formats.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function convertRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value instanceof Timestamp) {
                // Doctrine DBAL expects a string in the platform's format.
                // We provide microseconds if they are present, matching DBAL's expectations.
                $dt = $value->get();
                if ($dt instanceof DateTimeImmutable) {
                    $dt = $dt->setTimezone(new DateTimeZone('UTC'));
                } else {
                    $dt = clone $dt;
                    $dt->setTimezone(new DateTimeZone('UTC'));
                }

                $row[$key] = $dt->format($dt->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
            } elseif ($value instanceof Date) {
                $row[$key] = (string) $value;
            }
        }

        return $row;
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();

        if ($row === false) {
            return false;
        }

        return $row[0];
    }

    /**
     * {@inheritDoc}
     *
     * @return list<list<mixed>>
     */
    public function fetchAllNumeric(): array
    {
        $rows = [];

        while (($row = $this->fetchNumeric()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAllAssociative(): array
    {
        $rows = [];

        while (($row = $this->fetchAssociative()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     *
     * @return list<mixed>
     */
    public function fetchFirstColumn(): array
    {
        $rows = [];

        while (($row = $this->fetchOne()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function columnCount(): int
    {
        if ($this->spannerResult === null) {
            return 0;
        }

        $res = $this->spannerResult;
        assert($res instanceof SpannerResult);
        $cols = $res->columns();

        return $cols === null ? 0 : count($cols);
    }

    public function free(): void
    {
        $this->spannerResult = null;
        $this->iterator      = null;
    }
}
