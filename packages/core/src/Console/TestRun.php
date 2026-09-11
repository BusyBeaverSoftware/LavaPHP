<?php

declare(strict_types=1);

namespace Lava\Core\Console;

/**
 * One PHPUnit run, as structured data.
 *
 * This is what `lava test` reports and what `lava check`'s tests section reads
 * — the same object, so the two commands can never disagree about whether the
 * suite passed. `cases` deliberately carries only the cases that did NOT
 * simply pass: an agent asking "why is this red" wants the failures, and
 * listing 147 green cases would bury them.
 *
 * `tests` is the total (passed + everything else), so `tests - failures -
 * errors - skipped` is the green count without a second field.
 */
final readonly class TestRun
{
    /**
     * @param list<string> $suites testsuite names from the report, outermost first
     * @param list<array<string, mixed>> $cases only failed/errored/skipped cases
     */
    public function __construct(
        public ?string $suite,
        public array $suites,
        public int $tests,
        public int $failures,
        public int $errors,
        public int $skipped,
        public int $assertions,
        public float $time,
        public array $cases,
    ) {
    }

    /** Green means PHPUnit would have exited 0: no failures and no errors. */
    public function ok(): bool
    {
        return $this->failures === 0 && $this->errors === 0;
    }

    /** @return array<string, mixed> the stable `lava.test/1` / `lava.check/1` shape */
    public function json(): array
    {
        return [
            'suite' => $this->suite,
            'suites' => $this->suites,
            'tests' => $this->tests,
            'failures' => $this->failures,
            'errors' => $this->errors,
            'skipped' => $this->skipped,
            'assertions' => $this->assertions,
            'time' => $this->time,
            'cases' => $this->cases,
        ];
    }

    /** The one-line summary the text view prints, and `lava check` reuses. */
    public function summary(): string
    {
        return sprintf(
            'Tests: %d, Assertions: %d, Failures: %d, Errors: %d, Skipped: %d (%.3fs)',
            $this->tests,
            $this->assertions,
            $this->failures,
            $this->errors,
            $this->skipped,
            $this->time,
        );
    }
}
