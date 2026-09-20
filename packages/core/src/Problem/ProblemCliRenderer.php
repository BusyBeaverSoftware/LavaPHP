<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Console\PlainText;

/**
 * Renders a report as plain, grep-friendly terminal text. Deliberately no
 * ANSI colors: this output is routinely piped through agents and logs.
 * The FIX line is always present and always starts with "FIX:".
 *
 * A problem quotes the app's own files back — a config value, a `.env` key, a
 * route name — so every line it prints goes through {@see PlainText}: a value
 * carrying an escape sequence could otherwise rewrite the report that is
 * quoting it.
 */
final class ProblemCliRenderer
{
    public function render(ProblemReport $report): string
    {
        if ($report->isEmpty()) {
            return "LavaPHP: no problems.\n";
        }
        $out = sprintf(
            "LavaPHP found %d problem%s (%d fatal, %d warn)\n\n",
            $report->count(),
            $report->count() === 1 ? '' : 's',
            count($report->fatals()),
            count($report->warns()),
        );
        foreach ($report->problems() as $problem) {
            $out .= $this->renderProblem($problem) . "\n";
        }
        return $out;
    }

    public function renderProblem(LavaProblem $problem): string
    {
        $glyph = $problem->severity() === Severity::Fatal ? 'X' : '!';
        $out = sprintf("  [%s] [%s] %s\n", $glyph, $problem->severity()->value, $problem->code());
        $out .= sprintf("    PROBLEM: %s\n", PlainText::of($problem->getMessage()));
        $out .= sprintf("    FIX: %s\n", PlainText::of($problem->fix));
        if ($problem->source !== null) {
            $out .= sprintf("    AT: %s\n", PlainText::of((string) $problem->source));
        }
        foreach ($problem->context as $key => $value) {
            $out .= sprintf("    %s: %s\n", PlainText::of((string) $key), PlainText::of($this->renderValue($value)));
        }
        return $out;
    }

    private function renderValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '…';
    }
}