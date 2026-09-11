#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The coverage gate: measure the packs, report, and enforce a floor per pack.
 *
 * Run it with `composer coverage`. It is NOT part of `composer verify`, and the
 * reason is a prerequisite rather than a preference: coverage needs a driver
 * (pcov here) that a machine is not guaranteed to have, and a gate that cannot
 * run on a fresh checkout is a gate that gets skipped. `verify` stays runnable
 * anywhere; this runs where coverage is available — CI, and a dev machine with
 * the ini described below.
 *
 * ── Why this is a script and not `phpunit --coverage-text` ───────────────────
 *
 * A plain report would be a number nobody is held to, and the number it printed
 * would be wrong. The framework's own end-to-end tests prove a command by
 * RUNNING it — the harness spawns `bin/lava` and reads its stdout and its exit
 * code — so every line that only runs inside that child is invisible to the
 * parent's report. Measured that way the `db` pack reads 55%, and the four
 * `db:*` commands, the most thoroughly tested surface in the repository, read
 * as the least covered code in it. The measurement was the defect.
 *
 * So the harness captures the child's coverage too (see
 * `packages/core/tests/Support/fixture-autoload.php`, which every `bin/lava`
 * child is prepended with), and this script merges the two sources before it
 * says anything. `packages/db` reads 90%+ once the children are counted, which
 * is what it always was.
 *
 * ── The floors ───────────────────────────────────────────────────────────────
 *
 * A floor is a tripwire for rot, not a target: it is set just below what the
 * suite reaches, so a pack that LOSES coverage fails here and a pack that gains
 * it does not have to argue with a number. Two consequences worth stating:
 *
 *  - The `db` floor is set ABOVE what parent-only measurement can reach, on
 *    purpose. If the child-capture mechanism ever stops working — a broken
 *    prepend, a `pcov.directory` that no longer covers the repository — `db`
 *    drops to 55% and this gate fails loudly, instead of quietly reporting a
 *    smaller number as if it were the whole truth. The floor is the tripwire
 *    for the instrument as well as for the code.
 *  - The floors do not demand tests for states the public API cannot produce.
 *    A handful of guards are deliberately uncovered — an alias registered with
 *    a non-string target, a 405's `allowed` list, `class_exists(\PDO::class)`
 *    in a build without PDO — and they are named in DECISIONS.md rather than
 *    quietly excluded here. Writing a test that constructs one would be testing
 *    a state no caller can reach, which is coverage theatre, not coverage.
 *
 * ── A known artifact: fixtures built in a data provider ──────────────────────
 *
 * This report UNDERSTATES. PHPUnit's pcov driver calls `pcov\start()` when a
 * test begins and `pcov\clear()` when it ends, so anything executed during test
 * ENUMERATION — a `Query` constructed in a data provider, say — is never inside
 * a measured window and reads as uncovered however thoroughly it is asserted.
 * Measured on 2026-09-11 the effect was 10 lines across three files, and it is
 * why `packages/db/src/Query/Join.php` read 0.0% while `CompilerTest` asserted
 * the exact INNER and LEFT JOIN SQL: the compiler was well tested, the builder
 * that feeds it was not, and the report could not tell the two apart.
 *
 * The fix is in the tests, not here: a fixture a provider builds should be
 * built by a closure the test calls, which is what `QueryBuilderTest`,
 * `QueryBuilderChainTest` and `InvalidMigrationFileTest` do. That also closed
 * the real gap underneath — the query builder's `or*` family, both joins,
 * `whereIn`, `whereNotIn`, `whereBetween` and the success path of
 * `limit`/`offset` had no execution at all.
 *
 * A second suite run would measure this honestly (the parent can be captured
 * cumulatively when no coverage report is requested, so no driver clears it),
 * and it was not added: it doubles the gate's runtime to recover ten lines, and
 * a reader who sees a 0.0% here should suspect a provider-built fixture before
 * they suspect the code. See DECISIONS.md, "the second instrument artifact".
 */

$lavaRoot = realpath(__DIR__ . '/..');
if ($lavaRoot === false) {
    fwrite(STDERR, "coverage: cannot resolve the repository root from " . __DIR__ . "\n");
    exit(1);
}

/*
 * Arguments, read from the superglobal and narrowed rather than taken on trust.
 *
 * `$argv` is not guaranteed to exist — it is off when `register_argc_argv` is —
 * and `$_SERVER['argv']` is `mixed` as far as any static reader is concerned.
 * This script's whole job is to report a number honestly, so it starts by not
 * believing its own input: only the string elements become arguments.
 *
 * @var list<string> $arguments
 */
$arguments = [];
$rawArguments = $_SERVER['argv'] ?? null;
if (is_array($rawArguments)) {
    foreach ($rawArguments as $rawArgument) {
        if (is_string($rawArgument)) {
            $arguments[] = $rawArgument;
        }
    }
}

if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    echo <<<TXT
    Usage: composer coverage [-- --report-only] [-- --files[=N]]

      --report-only  measure and print, but never fail: what you run while
                     setting or re-setting a floor
      --files[=N]    also list the N weakest files per pack (default 10): the
                     actionable half of a failure

    Prerequisites, checked before anything runs:
      ext-pcov       loaded, with pcov.directory covering the repository
      ext-pdo_sqlite loaded, for the `db` pack's own CLI tests

    TXT;
    exit(0);
}

/** Report the number without enforcing it. */
$reportOnly = in_array('--report-only', $arguments, true);

/** How many of the weakest files to list per pack, or 0. */
$weakest = 0;
foreach ($arguments as $argument) {
    if ($argument === '--files') {
        $weakest = 10;
    } elseif (str_starts_with($argument, '--files=')) {
        $weakest = max(0, (int) substr($argument, strlen('--files=')));
    }
}

/**
 * The line-coverage floor per pack, as a percentage.
 *
 * Each sits about three points below the value measured on 2026-09-11 (958
 * tests), which is the headroom an ordinary refactor needs: a floor that sits
 * ON the measurement goes red the next time someone moves a branch, and a floor
 * nobody can satisfy is a floor that gets deleted rather than fixed. The
 * measurements, for whoever comes to re-set these:
 *
 *   packages/core         87.91%  ->  85.00
 *   packages/db           88.64%  ->  85.00
 *   packages/http-client  96.69%  ->  94.00
 *   packages/validate     98.29%  ->  96.00
 *   packages/view         97.73%  ->  95.00
 *
 * `db` is the one to read twice, in both directions. Measured without the child
 * capture it is 55%, so this floor is the tripwire for the instrument as well
 * as for the code. It was 78.00 until the query-builder chain tests landed and
 * took the pack from 81.14% to 88.64% — a floor 10.6 points below its pack is a
 * floor nobody reads, and the rule above says three. Raising it also WIDENS the
 * instrument tripwire: parent-only `db` is 55%, so the gap between a working
 * instrument and a broken one grew rather than shrank.
 *
 * See the note at the top of this file.
 *
 * @var array<string, float>
 */
const FLOORS = [
    'packages/core' => 85.0,
    'packages/db' => 85.0,
    'packages/http-client' => 94.0,
    'packages/validate' => 96.0,
    'packages/view' => 95.0,
];

// ── Prerequisites ────────────────────────────────────────────────────────────
// Each one fails with the command that fixes it, because a gate that says only
// "cannot run" costs the reader the same investigation every time.

if (!extension_loaded('pcov')) {
    coverage_fail(
        'ext-pcov is not loaded, so there is nothing to measure with.',
        'Load it, and make sure the CHILD processes get it too — `lava test` and `lava check` spawn '
            . '`bin/lava`, and coverage of those children is most of the point. Point PHP_INI_SCAN_DIR at '
            . 'a directory holding an ini with "extension=<path>/pcov.so", "pcov.enabled=1" and '
            . '"pcov.directory=' . $lavaRoot . '".',
    );
}

$pcovDirectory = (string) ini_get('pcov.directory');
if ($pcovDirectory === '') {
    coverage_fail(
        'pcov.directory is empty, so pcov decides for itself what to track.',
        'Set pcov.directory=' . $lavaRoot . ' in the ini that loads pcov. A child process runs with its '
            . 'working directory set to a fixture app in /tmp, so auto-detection tracks the wrong tree '
            . 'and the children contribute nothing.',
    );
}

$pcovReal = realpath($pcovDirectory);
if ($pcovReal === false || !str_starts_with($lavaRoot . '/', rtrim($pcovReal, '/') . '/')) {
    coverage_fail(
        "pcov.directory is {$pcovDirectory}, which does not cover the repository at {$lavaRoot}.",
        'Set pcov.directory=' . $lavaRoot . ' (or a parent of it) in the ini that loads pcov.',
    );
}

if (!extension_loaded('pdo_sqlite')) {
    coverage_fail(
        'ext-pdo_sqlite is not loaded, so the `db` pack\'s CLI tests skip.',
        'Install it (for example "apt install php-sqlite3"), or load it from an ini on '
            . 'PHP_INI_SCAN_DIR. This gate refuses to report a number it knows is short: the four db:* '
            . 'commands are exactly the code the child-capture exists to count.',
    );
}

$phpunit = $lavaRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
    coverage_fail(
        'vendor/bin/phpunit is missing.',
        'Run: composer install',
    );
}

// ── Run the suite, with the child capture switched on ────────────────────────

$artifacts = sys_get_temp_dir() . '/lava-coverage';
$children = $artifacts . '/children';
$clover = $artifacts . '/clover.xml';

coverage_remove_tree($artifacts);
mkdir($children, 0o755, true);

$env = getenv();
$env['LAVA_COVERAGE_DIR'] = $children;
// The live database tests need a DSN and skip without one. Coverage is meant to
// describe the whole suite, so the gate supplies the DSN the repository uses
// rather than reporting a smaller suite as if it were the suite.
$env['DB_TEST_DSN'] ??= 'sqlite::memory:';

fwrite(STDOUT, "coverage: running the suite with pcov (pcov.directory={$pcovDirectory})\n");

$process = proc_open(
    [PHP_BINARY, $phpunit, '--colors=never', '--coverage-clover=' . $clover],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $lavaRoot,
    $env,
);

if (!is_resource($process)) {
    coverage_fail('could not start PHPUnit.', 'Run: composer install');
}

$exit = proc_close($process);
if ($exit !== 0) {
    // A red suite is not a coverage question, and reporting a percentage for
    // one would be answering a different question than the reader asked.
    coverage_fail(
        "the suite exited {$exit}, so there is no coverage to report.",
        'Fix the suite first: vendor/bin/phpunit',
    );
}

// ── Merge: the parent's report plus every child's capture ────────────────────

/** @var array<string, array<int, int>> $lines relative path => line number => hits */
$lines = [];
$packages = [];

$cloverXml = @simplexml_load_file($clover);
if ($cloverXml === false) {
    coverage_fail(
        "PHPUnit wrote no readable clover report at {$clover}.",
        'Run: composer coverage -- --report-only to see PHPUnit\'s own output, which is passed through above.',
    );
}

foreach ($cloverXml->xpath('//file') ?? [] as $file) {
    $relative = coverage_relative((string) $file['name'], $lavaRoot);
    if (!coverage_is_pack_source($relative)) {
        continue;
    }
    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }
        $lines[$relative][(int) $line['num']] = (int) $line['count'];
    }
}

$universe = 0;
foreach ($lines as $fileLines) {
    $universe += count($fileLines);
}

$childFiles = glob($children . '/*.json');
$childFiles = $childFiles === false ? [] : $childFiles;
$childProcesses = count($childFiles);
$childHits = 0;
$childLines = 0;

foreach ($childFiles as $childFile) {
    $captured = json_decode((string) file_get_contents($childFile), true);
    if (!is_array($captured)) {
        continue;
    }

    foreach ($captured as $absolute => $hits) {
        $relative = coverage_relative((string) $absolute, $lavaRoot);
        if (!isset($lines[$relative]) || !is_array($hits)) {
            continue;
        }
        foreach ($hits as $number => $count) {
            $number = (int) $number;
            if (!array_key_exists($number, $lines[$relative])) {
                // pcov counts a line the report does not call executable — an
                // opening brace, a `case`, a closing tag. Real, but outside the
                // measured universe, so it cannot move a percentage.
                continue;
            }
            if ((int) $count <= 0 || $lines[$relative][$number] > 0) {
                continue;
            }
            // The line was not covered by the parent; the child is the only
            // reason it is covered at all.
            $lines[$relative][$number] = 1;
            $childHits++;
            $childLines++;
        }
    }
}

foreach ($lines as $relative => $fileLines) {
    $pack = coverage_pack($relative);
    $packages[$pack] ??= ['lines' => 0, 'hit' => 0, 'files' => []];
    $covered = 0;
    foreach ($fileLines as $count) {
        if ($count > 0) {
            $covered++;
        }
    }
    $packages[$pack]['lines'] += count($fileLines);
    $packages[$pack]['hit'] += $covered;
    $packages[$pack]['files'][$relative] = [count($fileLines), $covered];
}

ksort($packages);

// ── Report ───────────────────────────────────────────────────────────────────

$totalLines = 0;
$totalHit = 0;

echo "\n";
printf(
    "  %-24s %10s %8s   %8s   %s\n",
    'pack',
    'lines',
    'cover',
    'floor',
    'result',
);
printf("  %s\n", str_repeat('-', 66));

$failures = [];

foreach ($packages as $pack => $data) {
    $percentage = $data['lines'] === 0 ? 0.0 : 100 * $data['hit'] / $data['lines'];
    $floor = FLOORS[$pack] ?? null;

    $verdict = 'ok';
    if ($floor === null) {
        $verdict = 'no floor';
    } elseif ($percentage + 1e-9 < $floor) {
        $verdict = 'BELOW';
        $failures[$pack] = [$percentage, $floor];
    }

    printf(
        "  %-24s %10s %7.2f%%   %7s%%   %s\n",
        $pack,
        $data['hit'] . '/' . $data['lines'],
        $percentage,
        $floor === null ? '—' : number_format($floor, 2),
        $verdict,
    );

    $totalLines += $data['lines'];
    $totalHit += $data['hit'];
}

printf("  %s\n", str_repeat('-', 66));
printf(
    "  %-24s %10s %7.2f%%\n",
    'all packs',
    $totalHit . '/' . $totalLines,
    $totalLines === 0 ? 0.0 : 100 * $totalHit / $totalLines,
);

echo "\n";
printf("  executable lines measured: %d\n", $universe);
printf(
    "  child processes captured:  %d (%d lines the parent could not see)\n",
    $childProcesses,
    $childHits,
);

// A gate that cannot see its own instrument is the failure this check exists
// for. Two ways for the child capture to go quiet, and one test for both: the
// prepend stopped starting pcov (children write empty files), or the children
// stopped existing (nothing spawns `bin/lava` any more). Either way the numbers
// above are parent-only, and parent-only `db` is 55% — a report that would look
// like an ordinary bad day rather than a broken instrument.
if ($childHits === 0) {
    coverage_fail(
        'no child process contributed a line the parent had not already covered, so the `lava` '
            . 'subprocesses are not being measured.',
        'Check that packages/core/tests/Support/fixture-autoload.php still calls pcov\\start(), that '
            . 'pcov.directory covers ' . $lavaRoot . ', and that the end-to-end CLI tests still run '
            . '`bin/lava` as a subprocess rather than in-process.',
    );
}

if ($weakest > 0) {
    foreach ($packages as $pack => $data) {
        $worst = $data['files'];
        uasort($worst, static fn (array $a, array $b): int => ($a[1] / max(1, $a[0])) <=> ($b[1] / max(1, $b[0])));

        echo "\n  weakest files in {$pack}:\n";
        $shown = 0;
        foreach ($worst as $relative => $counts) {
            if ($counts[1] === $counts[0]) {
                continue;
            }
            printf(
                "    %6.1f%%  %4d missed  %s\n",
                $counts[0] === 0 ? 0.0 : 100 * $counts[1] / $counts[0],
                $counts[0] - $counts[1],
                substr($relative, strlen($pack) + 5),
            );
            if (++$shown >= $weakest) {
                break;
            }
        }
    }
    echo "\n";
}

if ($reportOnly) {
    echo "  --report-only: floors not enforced.\n\n";
    exit(0);
}

if ($failures === []) {
    echo "  every pack is at or above its floor.\n\n";
    exit(0);
}

echo "\n";
foreach ($failures as $pack => [$percentage, $floor]) {
    printf("  %s is at %.2f%%, below its floor of %.2f%%.\n", $pack, $percentage, $floor);
}
coverage_fail(
    count($failures) === 1 ? 'a pack lost coverage.' : count($failures) . ' packs lost coverage.',
    "See what changed: composer coverage -- --files\n"
        . '    If the drop is a real gap, close it. If a floor is genuinely wrong, change it in '
        . 'tools/coverage-check.php and say why in DECISIONS.md — a floor that is quietly lowered to '
        . 'make a run green is worse than no floor.',
);

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Report a problem the way the framework reports one: what is wrong, and the
 * command that fixes it. Never returns.
 */
function coverage_fail(string $problem, string $fix): never
{
    fwrite(STDERR, "\n  coverage: {$problem}\n\n  Fix: {$fix}\n\n");
    exit(1);
}

/** A repository-relative path with forward slashes, whatever the platform. */
function coverage_relative(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    $root = str_replace('\\', '/', $root);

    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}

/** Whether a relative path is a pack's shipped source — what the report measures. */
function coverage_is_pack_source(string $relative): bool
{
    return preg_match('#^packages/[^/]+/src/#', $relative) === 1;
}

/** The pack a relative source path belongs to: `packages/db/src/...` -> `packages/db`. */
function coverage_pack(string $relative): string
{
    return preg_match('#^(packages/[^/]+)/src/#', $relative, $matches) === 1
        ? $matches[1]
        : 'packages/other';
}

function coverage_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $path . '/' . $entry;
        is_dir($full) ? coverage_remove_tree($full) : @unlink($full);
    }
    @rmdir($path);
}
