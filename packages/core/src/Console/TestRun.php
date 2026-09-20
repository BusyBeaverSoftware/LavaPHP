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
 *
 * @phpstan-type TestCase array{name: string, class: string, file: string, line: int, status: string, type: string, message: string}
 *
 * @internal one test run as `lava test` and `lava check` report it
 */
final readonly class TestRun
{
    /**
     * @param list<string> $suites testsuite names from the report, outermost first
     * @param list<TestCase> $cases only failed/errored/skipped cases
     * @param int $exitCode the runner's own exit status — the verdict, where the
     *        report is only the detail. See {@see unreportedFailure()}.
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
        public int $exitCode = 0,
    ) {
    }

    /**
     * Green means PHPUnit would have exited 0: no failures, no errors, AND an
     * exit status of 0.
     *
     * The exit code is not a redundancy. `--log-junit` omits class-level setup
     * errors entirely, so a run can be red with a report that describes nothing
     * — and a verdict that read only the report would call that green. See
     * {@see unreportedFailure()}.
     */
    public function ok(): bool
    {
        return $this->exitCode === 0 && $this->failures === 0 && $this->errors === 0;
    }

    /**
     * True when the runner's exit code says something went wrong and the report
     * does not contain it.
     *
     * The narrow case, not "anything red": a suite with counted failures is
     * described by its report and stays the app's own finding. This is the run
     * the report cannot explain, and the only honest response is to say so
     * rather than to report the zero failures the XML actually contains.
     */
    public function unreportedFailure(): bool
    {
        return $this->exitCode !== 0 && $this->failures === 0 && $this->errors === 0;
    }

    /**
     * The framework's findings about the RUN — as opposed to the app's findings
     * about its own code, which are `failures`/`errors`/`cases`.
     *
     * It lives here rather than in the two commands that need it so they cannot
     * disagree, and cannot forget: `lava test` and `lava check` read the same
     * object and must reach the same verdict about it. A red suite whose report
     * describes it returns nothing, which is what keeps the framework out of
     * pronouncing on code it never read.
     *
     * @return list<\Lava\Core\Problem\LavaProblem>
     */
    public function problems(): array
    {
        if (!$this->unreportedFailure()) {
            return [];
        }

        return [\Lava\Core\Problem\IncompleteTestReport::of($this->exitCode, $this->tests, count($this->cases))];
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
