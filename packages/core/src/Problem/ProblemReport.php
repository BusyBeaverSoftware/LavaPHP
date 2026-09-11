<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * An ordered collection of problems. Boot steps accumulate here instead of
 * failing fast — the agent gets ALL problems in one pass, never one per run.
 */
final class ProblemReport
{
    /** @var list<LavaProblem> */
    private array $problems = [];

    public function add(LavaProblem $problem): void
    {
        $this->problems[] = $problem;
    }

    public function merge(self $other): void
    {
        foreach ($other->problems as $problem) {
            $this->problems[] = $problem;
        }
    }

    /** @return list<LavaProblem> in the order they were discovered */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<LavaProblem> */
    public function fatals(): array
    {
        return array_values(array_filter(
            $this->problems,
            static fn (LavaProblem $p): bool => $p->severity() === Severity::Fatal,
        ));
    }

    /** @return list<LavaProblem> */
    public function warns(): array
    {
        return array_values(array_filter(
            $this->problems,
            static fn (LavaProblem $p): bool => $p->severity() === Severity::Warn,
        ));
    }

    public function hasFatals(): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem->severity() === Severity::Fatal) {
                return true;
            }
        }
        return false;
    }

    public function isEmpty(): bool
    {
        return $this->problems === [];
    }

    public function count(): int
    {
        return count($this->problems);
    }

    /** @return list<array<string, mixed>> */
    public function json(): array
    {
        return array_map(static fn (LavaProblem $p): array => $p->json(), $this->problems);
    }
}