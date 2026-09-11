#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The PHP-floor gate: parse every file with the OLDEST PHP this repo claims to
 * support, and fail if any of them needs a newer one.
 *
 * Run it with `composer check:floor`. It is NOT part of `composer verify`, and
 * the reason is the prerequisite rather than a preference: it needs a PHP of
 * the floored version, which a dev machine generally does not have. `verify`
 * stays runnable anywhere; this runs where the older interpreter is reachable
 * — here, through docker.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * `composer.json` declares `"php": "^8.3"`. Nothing enforced it locally. PHP
 * 8.4 made `new Foo()->bar()` legal — parentheses-free `new` in member access —
 * and on 8.3 that is a PARSE error, not a deprecation, so a single such line
 * takes the whole file, and with it the whole suite, down. Three of them were
 * sitting in this repository and CI found them one push at a time: PHPUnit
 * aborts on the first file it cannot compile, so each 8.3 run cost a full push
 * cycle to learn about ONE line.
 *
 * That is the shape of the bug this gate closes. It lints every file in one
 * pass and prints every failure, so one local run is worth N CI runs.
 *
 * ── Why it lints with a real 8.3 rather than parsing with php-parser ─────────
 *
 * The obvious implementation — `nikic/php-parser`, already in vendor/, asking
 * it to parse at `PhpVersion::fromString('8.3')` — DOES NOT WORK, and it fails
 * silently, which is the worst way for a gate to fail. Measured on v5.8.0:
 * a parser built for 8.3 accepts `new Foo()->bar()` and property hooks without
 * complaint, because `ParserFactory::createForVersion()` says so itself — the
 * parser "will generally accept code for the newest supported version", and
 * only the LEXER is version-aware. It catches `:=`-style nonsense, not
 * newer-grammar syntax. Worse, the two spellings produce BYTE-IDENTICAL ASTs
 * (`Expr_MethodCall(var: Expr_New(...))` either way), so no AST visitor can
 * tell them apart afterwards either.
 *
 * A real interpreter has no such excuse: `php -l` on 8.3 rejects the line with
 * exactly the message CI printed. So the gate uses a real one.
 *
 * ── Prerequisites ───────────────────────────────────────────────────────────
 *
 * docker, unless the host PHP already IS the floored version — in which case
 * this lints in-process and needs nothing. When docker is required and absent,
 * the gate says so and names the fix rather than passing quietly: a floor
 * check that skips itself is indistinguishable from a floor that holds.
 */

$lavaRoot = realpath(__DIR__ . '/..');
if ($lavaRoot === false) {
    fwrite(STDERR, "floor: cannot resolve the repository root from " . __DIR__ . "\n");
    exit(1);
}

/*
 * Arguments, read from the superglobal and narrowed rather than taken on
 * trust — the same start `coverage-check.php` makes, for the same reason: this
 * script exists to report a fact honestly, so it begins by not believing its
 * own input.
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
    Usage: composer check:floor [-- --list]

      Lint every tracked PHP file with the oldest PHP version composer.json
      claims to support, and print every file that does not parse.

      --list   print the files that would be checked, and stop

    Prerequisites, checked before anything runs:
      docker            a `php:<floor>-cli` image, when the host is newer
      git               the file list comes from `git ls-files`

    TXT;
    exit(0);
}

$listOnly = in_array('--list', $arguments, true);

// ── The floor, read from the declaration it is meant to enforce ──────────────
// Read, not restated: a second copy of "8.3" in this file is a copy that can
// drift from composer.json, and the one that drifts is always the one nobody
// reads. The constraint shape is asserted rather than pattern-matched loosely,
// because a `>=7.4 || ^8.3` that quietly resolves to 7.4 would lint the whole
// repository against a version nobody supports and call the result a pass.

$composerJson = $lavaRoot . '/composer.json';
$manifest = json_decode((string) @file_get_contents($composerJson), true);
$constraint = is_array($manifest) ? ($manifest['require']['php'] ?? null) : null;

if (!is_string($constraint)) {
    floor_fail(
        "{$composerJson} declares no require.php, so there is no floor to check.",
        'Add the "php" entry to require, or delete this gate — a floor nobody declares is a '
            . 'floor nobody has.',
    );
}

if (preg_match('/^\^(\d+\.\d+)$/', $constraint, $matches) !== 1) {
    floor_fail(
        "require.php is {$constraint}, which this gate cannot read as a single floor.",
        'It understands the plain caret form (^8.3), which is the form this repository uses. '
            . 'Widen the pattern here if the constraint genuinely needs to be an expression — but '
            . 'then decide which single version this gate should lint with, and say why.',
    );
}

$floor = $matches[1];
$image = 'php:' . $floor . '-cli';

// ── The file list ────────────────────────────────────────────────────────────
// Tracked files AND untracked-but-not-ignored ones: a new file that has not
// been committed yet is exactly the file most likely to be written against the
// host's newer PHP, and it is the one a `git ls-files` alone would miss.

$listing = floor_run(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', '*.php'], $lavaRoot);
if ($listing['status'] !== 0) {
    floor_fail(
        'git ls-files failed, so there is no file list to check.',
        'Run this from a git checkout. git said: ' . trim($listing['stderr']),
    );
}

/** @var list<string> $files */
$files = [];
foreach (explode("\0", $listing['stdout']) as $path) {
    if (str_ends_with($path, '.php')) {
        $files[] = $path;
    }
}

if ($files === []) {
    floor_fail('no PHP files were found to check.', 'Run this from the repository root.');
}

if ($listOnly) {
    foreach ($files as $file) {
        echo $file, "\n";
    }
    printf("\n%d file(s) would be linted at php %s.\n", count($files), $floor);
    exit(0);
}

// ── Where the older interpreter comes from ───────────────────────────────────

$hostIsFloor = str_starts_with(PHP_VERSION, $floor . '.');

if ($hostIsFloor) {
    // The cheapest case, and the only one that needs no container at all: the
    // interpreter running this script IS the floor.
    $lint = static fn (string $file): array => floor_run([PHP_BINARY, '-l', $file], $lavaRoot);
    $how = 'this process (PHP ' . PHP_VERSION . ')';
} else {
    if (!floor_hasDocker()) {
        floor_fail(
            'the host runs PHP ' . PHP_VERSION . ', and docker is not available to supply ' . $floor . '.',
            'Start docker (or install PHP ' . $floor . ' and re-run — the host is used directly when it '
                . 'IS the floor). This gate refuses to report a pass it did not measure: linting ' . $floor
                . ' code with PHP ' . PHP_VERSION . ' would call every 8.4-ism above the floor legal, '
                . 'which is the exact failure it exists to catch.',
        );
    }

    fwrite(STDOUT, "floor: linting " . count($files) . " file(s) with {$image} (host is " . PHP_VERSION . ")\n");

    // One container for the whole list rather than one per file: a container
    // start dominates `php -l` by orders of magnitude, and a fresh container
    // per file would make this gate slow enough that people stop running it.
    // The list arrives on stdin and the results come back as one tagged line
    // per file, so every failure is reported rather than the first.
    $script = 'while IFS= read -r f; do '
        . 'if ! out=$(php -l "/repo/$f" 2>&1); then printf "%s\0%s\0" "$f" "$out"; fi; '
        . 'done < /floor-list';

    // The list is a temporary file mounted read-only rather than a file written
    // into the repository: the mount is `:ro`, so the container must not need
    // to write, and the repository must not collect a stray untracked file if
    // this script dies before it can clean up.
    $listFile = (string) tempnam(sys_get_temp_dir(), 'lava-floor-');
    file_put_contents($listFile, implode("\n", $files) . "\n");

    $result = floor_run(
        ['docker', 'run', '--rm', '-v', $lavaRoot . ':/repo:ro', '-v', $listFile . ':/floor-list:ro', $image, 'sh', '-c', $script],
        $lavaRoot,
    );
    @unlink($listFile);

    if ($result['status'] !== 0 && trim($result['stdout']) === '') {
        floor_fail(
            "could not lint with {$image}.",
            'Pull the image ("docker pull ' . $image . '"), or start the docker daemon. docker said: '
                . trim($result['stderr']),
        );
    }

    $failures = floor_readFailures($result['stdout']);
    floor_report($failures, count($files), $floor, $image);
    exit($failures === [] ? 0 : 1);
}

// ── The host-is-floor path: same report, no container ────────────────────────

$failures = [];
foreach ($files as $file) {
    $linted = $lint($lavaRoot . '/' . $file);
    if ($linted['status'] !== 0) {
        $failures[$file] = trim($linted['stdout'] . $linted['stderr']);
    }
}

floor_report($failures, count($files), $floor, $how);
exit($failures === [] ? 0 : 1);

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Report what did not parse, and the fix that is not "reformat the message".
 *
 * @param array<string, string> $failures file => the interpreter's own message
 */
function floor_report(array $failures, int $checked, string $floor, string $how): void
{
    if ($failures === []) {
        printf("floor: %d file(s) parse on PHP %s (%s).\n", $checked, $floor, $how);
        return;
    }

    fwrite(STDERR, "\n");
    foreach ($failures as $file => $message) {
        // Two cosmetic passes over the interpreter's own words. It prints an
        // `Errors parsing <path>` line after the real message, which says
        // nothing the header above it has not already said; and its message
        // carries the absolute path it was handed inside the container, where
        // the repository-relative one is what the reader can open and grep for.
        $lines = [];
        foreach (explode("\n", $message) as $line) {
            if (!str_starts_with(trim($line), 'Errors parsing ')) {
                $lines[] = str_replace('/repo/', '', $line);
            }
        }

        fwrite(STDERR, "  {$file}\n    " . implode("\n    ", $lines) . "\n");
    }

    floor_fail(
        count($failures) === 1
            ? '1 of ' . $checked . " files does not parse on PHP {$floor}."
            : count($failures) . ' of ' . $checked . " files do not parse on PHP {$floor}.",
        "Write the code the floor accepts. The common cause is a construct PHP 8.4 added — "
            . "`new Foo()->bar()` needs to be `(new Foo())->bar()`, and property hooks and "
            . 'asymmetric visibility have no 8.3 spelling at all. If the floor should move, change '
            . 'require.php in composer.json; this gate reads it, so the two cannot disagree.',
    );
}

/**
 * The tagged results the container wrote: a NUL-separated file/message pair per
 * failure.
 *
 * @return array<string, string>
 */
function floor_readFailures(string $stdout): array
{
    $parts = explode("\0", $stdout);
    $failures = [];
    for ($i = 0; $i + 1 < count($parts); $i += 2) {
        $file = $parts[$i];
        if ($file !== '') {
            $failures[$file] = trim($parts[$i + 1]);
        }
    }

    return $failures;
}

/**
 * Report a problem the way the framework reports one: what is wrong, and the
 * command that fixes it. Never returns.
 */
function floor_fail(string $problem, string $fix): never
{
    fwrite(STDERR, "\n  floor: {$problem}\n\n  Fix: {$fix}\n\n");
    exit(1);
}

/**
 * Run a command and collect its streams. An ARRAY command, never a shell
 * string: nothing here is quoted or interpreted, and the process PHP terminates
 * is the one it started rather than a shell wrapping it — which is what makes
 * the `docker run` below safe to build from parts.
 *
 * @param list<string> $command
 * @return array{status: int, stdout: string, stderr: string}
 */
function floor_run(array $command, string $cwd): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return ['status' => 127, 'stdout' => '', 'stderr' => 'could not start ' . ($command[0] ?? '?')];
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_close($process);

    return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Whether a docker CLI that can reach a daemon is on PATH. */
function floor_hasDocker(): bool
{
    $which = floor_run(['sh', '-c', 'command -v docker'], getcwd() ?: '/');
    if ($which['status'] !== 0 || trim($which['stdout']) === '') {
        return false;
    }

    $info = floor_run(['docker', 'info', '--format', '{{.ServerVersion}}'], getcwd() ?: '/');

    return $info['status'] === 0;
}
