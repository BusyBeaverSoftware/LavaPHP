<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * Renders a report as plain, grep-friendly terminal text. Deliberately no
 * ANSI colors: this output is routinely piped through agents and logs.
 * The FIX line is always present and always starts with "FIX:".
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
        $out .= sprintf("    PROBLEM: %s\n", $problem->getMessage());
        $out .= sprintf("    FIX: %s\n", $problem->fix);
        if ($problem->source !== null) {
            $out .= sprintf("    AT: %s\n", (string) $problem->source);
        }
        foreach ($problem->context as $key => $value) {
            $out .= sprintf("    %s: %s\n", $key, $this->renderValue($value));
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