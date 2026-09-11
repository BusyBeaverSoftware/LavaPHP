<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** Renders a report as pretty JSON — the exact same objects as {@see ProblemReport::json()}. */
final class ProblemJsonRenderer
{
    public function render(ProblemReport $report): string
    {
        return json_encode($report->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function renderProblem(LavaProblem $problem): string
    {
        return json_encode($problem->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}