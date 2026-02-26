<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner\SQL;

use Doctrine\DBAL\SQL\Parser;
use Doctrine\DBAL\SQL\Parser\Exception;
use Doctrine\DBAL\SQL\Parser\Visitor;

use function substr;

/**
 * Converts positional and named placeholders to Spanner's @ syntax.
 *
 * @internal
 */
final class PlaceholderConverter implements Visitor
{
    private string $sql          = '';
    private int $positionalCount = 0;

    /** @throws Exception */
    public function convert(string $sql): string
    {
        $this->sql             = '';
        $this->positionalCount = 0;

        // Spanner uses backslash escaping for strings in GoogleSQL.
        $parser = new Parser(true);
        $parser->parse($sql, $this);

        return $this->sql;
    }

    public function acceptNamedParameter(string $name): void
    {
        $this->sql .= '@' . substr($name, 1);
    }

    public function acceptPositionalParameter(string $value): void
    {
        $this->sql .= '@param' . ++$this->positionalCount;
    }

    public function acceptOther(string $value): void
    {
        $this->sql .= $value;
    }
}
