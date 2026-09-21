<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

use DateTimeImmutable;
use PhpMiniDatabase\Client\ResultSet;

/**
 * A `ResultSet`, rendered for a terminal or a file — PLAN.md §9.3's table
 * (the default) plus §9.4's `--json`/`--csv`/`--vertical` alternatives.
 * Pure formatting: no socket, no `Client\Connection`, so every shape here
 * is testable by constructing a `ResultSet` directly and reading back
 * whatever was written to a `php://memory` stream.
 */
final class ResultPrinter
{
    /** @param resource $stream */
    public function print(ResultSet $result, OutputFormat $format, mixed $stream): void
    {
        match ($format) {
            OutputFormat::TABLE => $this->printTable($result, $stream),
            OutputFormat::JSON => $this->printJson($result, $stream),
            OutputFormat::CSV => $this->printCsv($result, $stream),
            OutputFormat::VERTICAL => $this->printVertical($result, $stream),
        };
    }

    /**
     * The `N row(s) in set|affected (X sec)` line every format gets
     * printed after it (unless `--quiet`) — not part of `print()` itself,
     * since only the caller (`Command\QueryCommand`, `Repl`) knows how
     * long the request actually took.
     */
    public function statusLine(ResultSet $result, float $elapsedSeconds): string
    {
        $affected = $result->affectedRows();

        if ($affected !== null) {
            return sprintf('Query OK, %d row%s affected (%.3f sec)', $affected, $affected === 1 ? '' : 's', $elapsedSeconds);
        }

        if ($result->columns() === []) {
            return sprintf('Query OK (%.3f sec)', $elapsedSeconds);
        }

        $count = count($result);

        return sprintf('%d row%s in set (%.3f sec)', $count, $count === 1 ? '' : 's', $elapsedSeconds);
    }

    /** @param resource $stream */
    private function printTable(ResultSet $result, mixed $stream): void
    {
        $columns = $result->columns();

        // Zero columns only ever happens for DDL/transaction control
        // (Network\Protocol\ResultEncoder's own convention - a real
        // SELECT always names at least one column, even with zero rows),
        // and `statusLine()`'s "Query OK" already says so; there is
        // nothing this format adds by printing anything at all here.
        if ($columns === []) {
            return;
        }

        $rows = array_map(
            fn (array $row): array => array_map($this->cellText(...), array_values($row)),
            $result->fetchAll(),
        );

        $widths = array_map(mb_strlen(...), $columns);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strlen($cell));
            }
        }

        $separator = '+' . implode('+', array_map(static fn (int $w): string => str_repeat('-', $w + 2), $widths)) . '+';

        fwrite($stream, $separator . "\n");
        fwrite($stream, $this->tableRow($columns, $widths) . "\n");
        fwrite($stream, $separator . "\n");

        foreach ($rows as $row) {
            fwrite($stream, $this->tableRow($row, $widths) . "\n");
        }

        fwrite($stream, $separator . "\n");
    }

    /**
     * @param list<string> $cells
     * @param list<int>    $widths
     */
    private function tableRow(array $cells, array $widths): string
    {
        $padded = [];

        foreach ($cells as $i => $cell) {
            $padded[] = ' ' . str_pad($cell, $widths[$i]) . ' ';
        }

        return '|' . implode('|', $padded) . '|';
    }

    /** @param resource $stream */
    private function printJson(ResultSet $result, mixed $stream): void
    {
        $rows = array_map(
            fn (array $row): array => array_map($this->jsonValue(...), $row),
            $result->fetchAll(),
        );

        fwrite($stream, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private function jsonValue(mixed $value): mixed
    {
        return $value instanceof DateTimeImmutable ? $value->format('Y-m-d H:i:s') : $value;
    }

    /** @param resource $stream */
    private function printCsv(ResultSet $result, mixed $stream): void
    {
        $columns = $result->columns();

        if ($columns === []) {
            return;
        }

        fputcsv($stream, $columns, escape: '\\');

        foreach ($result->fetchAll() as $row) {
            fputcsv($stream, array_map($this->cellText(...), array_values($row)), escape: '\\');
        }
    }

    /** @param resource $stream */
    private function printVertical(ResultSet $result, mixed $stream): void
    {
        $columns = $result->columns();

        if ($columns === []) {
            return;
        }

        $width = max(array_map(mb_strlen(...), $columns));
        $rowNumber = 0;

        foreach ($result->fetchAll() as $row) {
            $rowNumber++;
            fwrite($stream, sprintf("*************************** %d. row ***************************\n", $rowNumber));

            foreach ($row as $column => $value) {
                fwrite($stream, sprintf("%s: %s\n", str_pad($column, $width, ' ', STR_PAD_LEFT), $this->cellText($value)));
            }
        }
    }

    private function cellText(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof DateTimeImmutable => $value->format('Y-m-d H:i:s'),
            default => (string) $value,
        };
    }
}
