<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\IO;
use Lava\Core\Console\PhpUnitRunner;
use Lava\Core\Console\Table;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;

/**
 * `lava test` — runs the app's own PHPUnit and reports the result as data.
 *
 * A plain Command, not an AppCommand: a test suite does not need a booted app
 * to be runnable, and refusing to run the tests of an app that cannot boot is
 * exactly backwards — a red suite is often WHY the app cannot boot. Tying the
 * two together is `lava check`'s job, which reports both side by side.
 *
 * Red tests exit 1 but are NOT problems: they are the app's findings, and
 * rendering them as framework problems would have the framework pronounce on
 * code it never read. `problems[]` stays for framework findings.
 *
 * @phpstan-import-type TestCase from \Lava\Core\Console\TestRun
 */
final class TestCommand extends Command
{
    public function name(): string
    {
        return 'test';
    }

    public function summary(): string
    {
        return "Run the app's PHPUnit suite and report structured results.";
    }

    public function flags(): array
    {
        return ['json', 'filter', 'env'];
    }

    public function usage(): string
    {
        return 'lava test [--filter=<pattern>] [--env=<name>] [--json]';
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        // Seeded first so every exit path carries the same keys — a consumer
        // can always read `tests`, whether the suite is green, red, or missing.
        foreach (self::emptyPayload() as $key => $value) {
            $io->data($key, $value);
        }

        $filter = $args->value('filter');
        try {
            $run = (new PhpUnitRunner($appDir))->run($filter, $args->value('env'));
        } catch (LavaProblem $problem) {
            $report = new ProblemReport();
            $report->add($problem);
            return $io->emit($this->name(), $report);
        }

        foreach ($run->json() as $key => $value) {
            $io->data($key, $value);
        }

        // A run can be red with a report that describes nothing — a class-level
        // setup error is absent from --log-junit entirely. Those findings come
        // from the run itself; a suite whose failures ARE in the report
        // contributes none, which is what keeps the framework from pronouncing
        // on code it never read.
        $report = new ProblemReport();
        foreach ($run->problems() as $problem) {
            $report->add($problem);
        }

        $io->text($run->summary() . "\n");
        if ($filter !== null) {
            $io->text("filter: {$filter}\n");
        }
        $io->text((new Table(['status', 'class', 'test', 'message'], array_map(self::caseRow(...), $run->cases)))->render());

        return $io->emit($this->name(), $report, failed: !$run->ok());
    }

    /**
     * One failed/errored/skipped case as one table row.
     *
     * A named method rather than an inline closure so the case shape reaches
     * the cells: a closure parameter declared `array` would erase it and leave
     * four `mixed` values needing casts. Nothing here casts — the shape says
     * these are already strings, and the analyser holds the shape to account.
     *
     * @param TestCase $case
     * @return list<string>
     */
    private static function caseRow(array $case): array
    {
        return [
            $case['status'],
            $case['class'],
            $case['name'],
            self::firstLine($case['message']),
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyPayload(): array
    {
        return [
            'suite' => null,
            'suites' => [],
            'tests' => 0,
            'failures' => 0,
            'errors' => 0,
            'skipped' => 0,
            'assertions' => 0,
            'time' => 0.0,
            'cases' => [],
        ];
    }

    /** A table cell is one line: a failure message runs to a whole stack. */
    private static function firstLine(string $message): string
    {
        $line = trim(strtok($message, "\n") ?: '');
        return strlen($line) > 120 ? substr($line, 0, 117) . '…' : $line;
    }
}
