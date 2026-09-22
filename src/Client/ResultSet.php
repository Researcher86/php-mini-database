<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Client;

use Countable;
use Generator;
use IteratorAggregate;

/**
 * A `QUERY_RESULT`'s rows, already fully materialized by the time they
 * reach here (see `Message\QueryResultMessage`'s own docblock — this
 * protocol does not stream), paired with their column names so a caller
 * gets `$row['email']`, not `$row[1]`.
 *
 * `fetch()` keeps its own cursor (`$cursor`, the one mutable property
 * here — everything else is `readonly`), for the PDO-style "loop until
 * null" caller; `foreach ($result as $row)` (via `getIterator()`) does not
 * share it — every `foreach` walks all rows again from the start, the
 * same as iterating a plain array twice, since every row is already
 * sitting in memory with nothing left to consume from the wire. Mixing
 * both styles on the same `ResultSet` is therefore safe, if unusual.
 *
 * `affectedRows()` reads back the convention `Network\Protocol\ResultEncoder`
 * documents on the server side: an `INSERT`/`UPDATE`/`DELETE` becomes one
 * `affected_rows` column and one row naming it, and a DDL or
 * transaction-control statement becomes zero columns and zero rows — both
 * recognizable from `$columns` alone, without a fourth message type. This
 * is the client half of that same convention; `Connection::execute()` is
 * what actually calls it.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class ResultSet implements IteratorAggregate, Countable
{
    private int $cursor = 0;

    /**
     * @param list<string>      $columns
     * @param list<list<mixed>> $rows
     */
    public function __construct(
        private readonly array $columns,
        private readonly array $rows,
    ) {
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** @return array<string, mixed>|null */
    public function fetch(): ?array
    {
        return $this->rowAt($this->cursor++);
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(): array
    {
        // Independent of fetch()'s cursor, deliberately: getIterator()
        // walks from the start every time (see the class docblock), so
        // fetchAll() after a partial fetch() loop still returns the whole
        // result rather than the remainder.
        return iterator_to_array($this->getIterator(), false);
    }

    public function affectedRows(): ?int
    {
        if ($this->columns === ['affected_rows'] && count($this->rows) === 1) {
            return (int) $this->rows[0][0];
        }

        return null;
    }

    /** @return Generator<int, array<string, mixed>> */
    public function getIterator(): Generator
    {
        foreach ($this->rows as $row) {
            yield array_combine($this->columns, $row);
        }
    }

    /** @return array<string, mixed>|null */
    private function rowAt(int $index): ?array
    {
        if (!isset($this->rows[$index])) {
            return null;
        }

        return array_combine($this->columns, $this->rows[$index]);
    }
}
