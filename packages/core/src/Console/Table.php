<?php

declare(strict_types=1);

namespace Lava\Core\Console;

/**
 * Plain aligned-column text for humans. No ANSI, no box drawing: `lava`
 * output is routinely piped into agents and logs, and a table that survives
 * `grep` is worth more than one that survives a screenshot.
 *
 * `--json` is the machine contract; this is the courtesy view. Every command
 * that has a table also has an envelope, never one without the other.
 */
final class Table
{
    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function __construct(
        private readonly array $headers,
        private readonly array $rows,
    ) {
    }

    public function render(): string
    {
        if ($this->rows === []) {
            return '(none)' . "\n";
        }

        // Before anything is measured: a cell holding an escape sequence or a
        // newline would otherwise print a row nobody wrote, and be measured at
        // a width the terminal does not use ({@see PlainText}).
        $headers = array_map(PlainText::of(...), $this->headers);
        $rows = array_map(
            static fn (array $row): array => array_map(PlainText::of(...), $row),
            $this->rows,
        );

        $widths = array_map(static fn (string $h): int => strlen($h), $headers);
        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, strlen($cell));
            }
        }

        $lines = [$this->formatRow($headers, $widths)];
        $lines[] = implode('  ', array_map(
            static fn (int $w): string => str_repeat('-', $w),
            $widths,
        ));
        foreach ($rows as $row) {
            $lines[] = $this->formatRow($row, $widths);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     */
    private function formatRow(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($widths as $index => $width) {
            $padded[] = str_pad($cells[$index] ?? '', $width);
        }
        return rtrim(implode('  ', $padded));
    }
}
