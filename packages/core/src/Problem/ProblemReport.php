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

    /**
     * Whether an identical problem is already here: the same code AND the same
     * context.
     *
     * Identity is the diagnosis, not the object. One broken factory is
     * resolved again by every service that depends on it, and each resolution
     * throws a fresh instance of the same problem — so a report that compared
     * objects would say "SESSION_SECRET is empty" once per dependent, inflating
     * every count and the reader's sense of how broken the app is. Two problems
     * with the same code and the same context tell the reader the same thing
     * and have the same fix.
     */
    public function includes(LavaProblem $problem): bool
    {
        $key = self::key($problem);
        foreach ($this->problems as $existing) {
            if (self::key($existing) === $key) {
                return true;
            }
        }
        return false;
    }

    private static function key(LavaProblem $problem): string
    {
        return $problem->code() . '|' . json_encode($problem->context);
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