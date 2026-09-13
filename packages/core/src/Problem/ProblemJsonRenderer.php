<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * Renders a report as pretty JSON — the exact same objects as {@see ProblemReport::json()}.
 *
 * Invalid UTF-8 in a message or context is substituted rather than thrown, as
 * the HTTP renderer does: a report that cannot be printed hides the problem.
 */
final class ProblemJsonRenderer
{
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function render(ProblemReport $report): string
    {
        return json_encode($report->json(), self::FLAGS);
    }

    public function renderProblem(LavaProblem $problem): string
    {
        return json_encode($problem->json(), self::FLAGS);
    }
}
