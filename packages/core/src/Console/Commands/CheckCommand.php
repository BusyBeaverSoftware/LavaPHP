<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Config\EnvAudit;
use Lava\Core\Console\AppBoot;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\PhpUnitRunner;
use Lava\Core\Console\Table;
use Lava\Core\Console\TestRun;
use Lava\Core\Map\MapDocument;
use Lava\Core\Map\ProjectMap;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\Severity;

/**
 * `lava check` — THE one-command verification loop. Boot, wiring, routes,
 * features, and the app's own test suite, in one envelope, with one exit code.
 *
 * The design constraint is the tight agent loop: an agent should be able to
 * ask "is this app correct?" once, cheaply, and get back a single fix-first
 * problem list rather than a sequence of commands it has to know to run. So
 * this command is not a wrapper around the other commands — it is the one
 * place that runs every check and merges the findings.
 *
 * Three things distinguish it from just running `lava routes` and `lava test`:
 *
 *  - **sections**: the merged problems are grouped by area, so a reader sees
 *    WHICH part of the app is broken before reading any individual problem;
 *  - **fix-first order**: problems whose fix is a runnable command come first,
 *    because those are the ones an agent can act on without judgement;
 *  - **`--strict`**: warnings (an unset required env var, say) become failures,
 *    which is what a build or CI wants and what a diagnostic must not do.
 *
 * It survives a failed boot on purpose: a red test suite is often the reason an
 * app cannot boot, so the tests still run and the report carries both.
 */
final class CheckCommand extends AppCommand
{
    /**
     * Problem code => section. A code missing from this map lands in 'boot',
     * so the map can never hide a problem — only file it less specifically.
     *
     * 'tests' is listed here even though its section is built separately, so a
     * runner the app does not have is reported as a TEST finding rather than a
     * boot failure: the app booted fine, and saying otherwise would send an
     * agent to read app/ files that are not the problem.
     */
    private const SECTIONS = [
        'routes' => ['bad_route_pattern', 'bad_handler', 'bad_middleware', 'duplicate_route_name'],
        'features' => ['unknown_feature', 'duplicate_feature', 'unknown_env_branch', 'invalid_flag_value', 'invalid_feature_name'],
        'wiring' => ['service_not_registered', 'duplicate_service', 'circular_service', 'invalid_gating', 'missing_pack', 'module_mismatch'],
        'config' => ['invalid_config', 'invalid_env_file'],
        'env' => ['missing_env_var'],
        'commands' => ['duplicate_command'],
        'map' => ['stale_map'],
        'tests' => ['missing_test_runner', 'bad_test_report', 'incomplete_test_report'],
    ];

    /** The order sections are reported in — the order an app is built in. */
    private const ORDER = ['boot', 'config', 'wiring', 'routes', 'features', 'env', 'commands', 'map', 'tests'];

    /**
     * Sections whose every check is a sweep `--quick` skips, so under `--quick`
     * they say `skipped` rather than an `ok` for a check that never ran. `env`
     * is the env audit and `map` the freshness check; neither has a code boot
     * can raise. `features` is not here: boot validates every definition, and
     * only the per-flag resolution sweep is skipped.
     */
    private const SWEEPS = ['env', 'map'];

    public function name(): string
    {
        return 'check';
    }

    public function summary(): string
    {
        return 'Verify the app in one command: boot, wiring, routes, features, and tests.';
    }

    public function flags(): array
    {
        return ['json', 'quick', 'strict', 'no-tests', 'filter', 'env'];
    }

    public function usage(): string
    {
        return 'lava check [--quick] [--strict] [--no-tests] [--filter=<pattern>] [--env=<name>] [--json]';
    }

    public function emptyPayload(Args $args): array
    {
        return [
            'sections' => [],
            'counts' => self::zeroCounts(),
            'tests' => null,
            // Both are functions of how the invocation was TYPED, not of
            // anything inspected, so they are knowable before the boot — and
            // both are required keys, so leaving them to `report()` meant the
            // envelopes emitted before it (a problem thrown by a section, and
            // any invocation the kernel rejects) claimed `lava.check/2` while
            // missing two of its properties. The schema's `additionalProperties:
            // false` is what turned that into a failing test rather than a
            // payload an agent silently mis-reads.
            'strict' => $args->bool('strict'),
            'quick' => $args->bool('quick'),
        ];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        return $this->report($io, $args, $app, $app->problems);
    }

    protected function inspectFailure(IO $io, Args $args, BootFailure $failure): int
    {
        // No app means no counts and no feature sweep — but the suite is still
        // runnable, and it is the single most useful thing to know when boot
        // just failed. The directory comes off the failure itself.
        $this->failureDir = $failure->appDir;
        return $this->report($io, $args, null, $failure->problems);
    }

    /** Set by inspectFailure so the tests section can still find the app dir. */
    private ?string $failureDir = null;

    private function report(IO $io, Args $args, ?App $app, ProblemReport $boot): int
    {
        // Boot already reported everything it found. A second pass can find the
        // same problem again (a flag resolved at boot and re-resolved here), and
        // reporting it twice would inflate every count and the reader's sense of
        // how broken the app is. Same code + same context IS the same problem.
        $report = self::dedupe($boot);

        // Two sweeps over things boot cannot notice, both for the same reason:
        // a definition nothing uses is never resolved, so it cannot report on
        // itself. A feature with a bad per-env branch and a required env var
        // with no value are both invisible to a green boot, and `check` is the
        // only place that looks. `--quick` skips them with the tests.
        if ($app !== null && !$args->bool('quick')) {
            foreach ($app->features->definitions->names() as $name) {
                try {
                    $app->features->resolve($name);
                } catch (LavaProblem $problem) {
                    self::add($report, $problem);
                }
            }

            foreach (EnvAudit::missing($app) as $problem) {
                self::add($report, $problem);
            }

            // The map, last of the sweeps: is the committed AGENTS.md still an
            // accurate description of this app? Only a map that EXISTS can be
            // stale — `check` does not demand the artifact, because an app that
            // chose not to ship one has no drift to catch, and a warning nobody
            // can act on is noise. `lava map --check` answers the other question
            // ("is it there and current?") and does report it as missing.
            // A warn, so a red map never fails `check` unless --strict says so.
            $document = MapDocument::at($app->appDir);
            if ($document->exists()) {
                // Compiled from a boot with every installed pack's gate on, so a
                // pack this environment switches off is not read as drift — that
                // made `--strict` fail on a deploy that changed nothing (Lava
                // Notes, R3-B11). When that boot fails, its problems are the
                // answer: a verdict reached without a pack's declarations would
                // be wrong whichever way it fell.
                $mapped = AppBoot::forMap($app, $args->value('env'));
                if ($mapped instanceof BootFailure) {
                    foreach ($mapped->problems->problems() as $problem) {
                        self::add($report, $problem);
                    }
                } else {
                    $staleness = ProjectMap::of($mapped)->staleness($document);
                    if ($staleness !== null) {
                        self::add($report, $staleness);
                    }
                }
            }
        }

        [$tests, $skipReason] = $this->runTests($args, self::appDirOf($app, $this->failureDir), $report);
        $counts = $app === null ? self::zeroCounts() : [
            'routes' => count($app->router->routes()),
            'services' => count($app->container->ids()),
            'features' => count($app->features->definitions->names()),
            'commands' => count($app->commands()->all()),
            'middleware' => count($app->globalMiddleware),
        ];

        $sections = self::sections($report, $tests, $skipReason, $args->bool('strict'), $args->bool('quick'));
        $ordered = self::fixFirst($report);
        $strictFail = $args->bool('strict') && !$report->isEmpty();

        $io->data('sections', $sections);
        $io->data('counts', $counts);
        $io->data('tests', $tests?->json());
        $io->data('strict', $args->bool('strict'));
        $io->data('quick', $args->bool('quick'));

        $io->text((new Table(['section', 'status', 'detail'], array_map(
            static fn (array $section): array => [
                (string) $section['name'],
                (string) $section['status'],
                (string) $section['detail'],
            ],
            $sections,
        )))->render());
        $io->text(sprintf(
            "routes: %d  services: %d  features: %d  commands: %d\n",
            $counts['routes'],
            $counts['services'],
            $counts['features'],
            $counts['commands'],
        ));

        $failed = $tests !== null && !$tests->ok();
        return $io->emit($this->name(), $ordered, failed: $failed || $strictFail);
    }

    /**
     * Where the suite lives. A booted app knows; a failed boot recorded the
     * directory it was pointed at. The runner only ever needs the path, which
     * is why it takes one — the app it came from is already the caller's
     * business, and a null-safe read here would only obscure that.
     */
    private static function appDirOf(?App $app, ?string $failureDir): ?string
    {
        return $app === null ? $failureDir : $app->appDir;
    }

    /**
     * Runs the app's suite unless this invocation asked to skip it.
     *
     * `--quick` is the tight-loop switch (<2s budget): it skips the tests AND
     * the all-features sweep, keeping only what boot already did.
     * `--no-tests` skips only the suite, for a caller that wants the framework's
     * verdict while its tests are legitimately red.
     *
     * @return array{0: TestRun|null, 1: string|null} the run, and why it did not happen
     */
    private function runTests(Args $args, ?string $appDir, ProblemReport $report): array
    {
        $skip = $args->bool('quick') ? '--quick' : ($args->bool('no-tests') ? '--no-tests' : null);
        if ($skip !== null) {
            return [null, $skip];
        }

        if ($appDir === null) {
            return [null, 'app directory unknown'];
        }

        try {
            $run = (new PhpUnitRunner($appDir))->run($args->value('filter'), $args->value('env'));
            // The run's own findings — a report that cannot explain the runner's
            // exit code — are the framework's, so they join the report and land
            // in the tests section with the other runner-level problems.
            foreach ($run->problems() as $problem) {
                self::add($report, $problem);
            }
            return [$run, null];
        } catch (LavaProblem $problem) {
            // A missing runner is a problem, not a skip: `lava check` promising
            // a verification it could not perform has to say so.
            self::add($report, $problem);
            return [null, 'no runner'];
        }
    }

    /**
     * Problems in the order an agent can act on them.
     *
     * Severity is the MAJOR key: a warning must never be hoisted above a fatal.
     * Within one severity, a fix that is a runnable command comes first, because
     * those need no judgement — the original rule, and the reason it is now the
     * minor key rather than the only one. `stale_map` is what exposed the flaw:
     * its fix IS a runnable command, but it is a warning, and sorting it above
     * "your route does not compile" would hand an agent the cheapest task first
     * and call it the most urgent. `usort` is stable from PHP 8.0, so discovery
     * order is preserved inside each of the four groups.
     */
    private static function fixFirst(ProblemReport $report): ProblemReport
    {
        $problems = $report->problems();
        usort($problems, static fn (LavaProblem $a, LavaProblem $b): int => self::rank($a) <=> self::rank($b));

        $ordered = new ProblemReport();
        foreach ($problems as $problem) {
            $ordered->add($problem);
        }
        return $ordered;
    }

    /** Fatal-and-runnable, fatal, warn-and-runnable, warn — in that order. */
    private static function rank(LavaProblem $problem): int
    {
        return ($problem->severity() === Severity::Fatal ? 0 : 2)
            + (str_starts_with($problem->fix, 'Run:') ? 0 : 1);
    }

    private static function dedupe(ProblemReport $report): ProblemReport
    {
        $unique = new ProblemReport();
        foreach ($report->problems() as $problem) {
            self::add($unique, $problem);
        }
        return $unique;
    }

    /** Adds unless an identical problem (same code AND same context) is present. */
    private static function add(ProblemReport $report, LavaProblem $problem): void
    {
        if (!$report->includes($problem)) {
            $report->add($problem);
        }
    }


    /**
     * @return list<array{name: string, status: string, problems: int, detail: string}>
     */
    private static function sections(ProblemReport $report, ?TestRun $tests, ?string $skipReason, bool $strict, bool $quick): array
    {
        $bySection = [];
        foreach ($report->problems() as $problem) {
            $bySection[self::sectionOf($problem->code())][] = $problem;
        }

        $sections = [];
        foreach (self::ORDER as $name) {
            $problems = $bySection[$name] ?? [];
            if ($name === 'tests') {
                $sections[] = self::testSection($tests, $skipReason, $problems, $strict);
                continue;
            }
            if ($quick && in_array($name, self::SWEEPS, true)) {
                $sections[] = ['name' => $name, 'status' => 'skipped', 'problems' => 0, 'detail' => '--quick'];
                continue;
            }
            $fatals = array_filter($problems, static fn (LavaProblem $p): bool => $p->severity() === Severity::Fatal);
            // Strict escalates: a warning is a failure in a build, and this
            // flag exists precisely so CI and a diagnostic can disagree.
            $failed = $fatals !== [] || ($strict && $problems !== []);
            $sections[] = [
                'name' => $name,
                'status' => $failed ? 'failed' : 'ok',
                'problems' => count($problems),
                'detail' => $problems === [] ? '' : implode(', ', array_unique(array_map(
                    static fn (LavaProblem $p): string => $p->code(),
                    $problems,
                ))),
            ];
        }
        return $sections;
    }

    /**
     * The tests section carries two kinds of news: what the run found, and
     * whether a run could happen at all. Both belong here — a section that
     * said 'skipped' while a fatal `missing_test_runner` sat under 'boot'
     * would be telling the reader two different stories.
     *
     * @param list<LavaProblem> $problems findings about the run itself
     * @return array{name: string, status: string, problems: int, detail: string}
     */
    private static function testSection(?TestRun $tests, ?string $skipReason, array $problems, bool $strict): array
    {
        $codes = implode(', ', array_unique(array_map(
            static fn (LavaProblem $p): string => $p->code(),
            $problems,
        )));

        if ($tests !== null) {
            $detail = $codes === '' ? $tests->summary() : $codes . ' — ' . $tests->summary();
            $count = count($problems) + $tests->failures + $tests->errors;
        } else {
            $detail = $codes === '' ? ($skipReason ?? 'not run') : $codes;
            $count = count($problems);
        }

        $fatals = array_filter($problems, static fn (LavaProblem $p): bool => $p->severity() === Severity::Fatal);
        $failed = $fatals !== [] || ($tests !== null && !$tests->ok()) || ($strict && $problems !== []);

        return [
            'name' => 'tests',
            'status' => $failed ? 'failed' : ($tests === null && $problems === [] ? 'skipped' : 'ok'),
            'problems' => $count,
            'detail' => $detail,
        ];
    }

    private static function sectionOf(string $code): string
    {
        foreach (self::SECTIONS as $section => $codes) {
            if (in_array($code, $codes, true)) {
                return $section;
            }
        }
        return 'boot';
    }

    /** @return array<string, int> */
    private static function zeroCounts(): array
    {
        return ['routes' => 0, 'services' => 0, 'features' => 0, 'commands' => 0, 'middleware' => 0];
    }
}
