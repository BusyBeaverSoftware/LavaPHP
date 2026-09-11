<?php

declare(strict_types=1);

/**
 * Stands in for what a real app's composer.json does: maps App\ to the app's
 * own app/ directory. Fixture apps have no composer.json (they are fixtures,
 * not installs), so the CLI test harness prepends this file into the child
 * process — without it, every App\ class is unfindable and a perfectly good
 * fixture boots with bad_handler / bad_middleware problems.
 *
 * The app dir arrives through LAVA_FIXTURE_APP_DIR because auto_prepend_file
 * cannot take an argument. In-process tests do the same thing by hand — see
 * {@see \Lava\Core\Testing\TestApp::autoloadFixture()}.
 */

if (!($GLOBALS['lava_fixture_autoloader'] ?? false)) {
    $GLOBALS['lava_fixture_autoloader'] = true;
    spl_autoload_register(static function (string $class): void {
        $appDir = getenv('LAVA_FIXTURE_APP_DIR');
        if ($appDir === false || !str_starts_with($class, 'App\\')) {
            return;
        }
        $file = rtrim($appDir, '/') . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

/*
 * ── Coverage, when the run asks for it ───────────────────────────────────────
 *
 * The framework's own end-to-end tests prove a command by RUNNING it: the
 * harness spawns `bin/lava` and reads its stdout, its stderr and its exit code.
 * That is the only way to prove the binary an agent shells out to, and it has
 * one blind spot — a line executed only in the child is invisible to the
 * parent's coverage report. So the pack tested hardest through the CLI, `db`
 * with its four commands, read as the least covered pack in the repository.
 *
 * When `LAVA_COVERAGE_DIR` names a directory, this file closes that gap: pcov
 * starts before `bin/lava` is compiled, and a shutdown function writes what ran
 * to a file of its own in that directory. The parent merges them — see
 * tools/coverage-check.php, which is the only thing that sets this variable.
 *
 * Off unless asked for, and silent when pcov is absent: an ordinary `composer
 * verify` run behaves exactly as it did before this block existed. The harness
 * forwards the variable to the child by not stripping it — see
 * {@see \Lava\Core\Tests\Support\LavaCli::isLavaVar()}, which exists to stop a
 * CI environment steering a fixture's FLAGS, not to hide a measurement.
 */
$lavaCoverageDir = getenv('LAVA_COVERAGE_DIR');
if (is_string($lavaCoverageDir) && $lavaCoverageDir !== '' && extension_loaded('pcov')) {
    \pcov\start();

    register_shutdown_function(static function () use ($lavaCoverageDir): void {
        \pcov\stop();

        $files = [];
        foreach (\pcov\collect() as $file => $lines) {
            // `pcov.directory` is the whole repository, so this also sees
            // vendor/ and the fixture app itself. The report is about
            // `packages/*/src`; the merge drops the rest, and dropping it here
            // keeps each child's file small — there is one per spawned process.
            if (str_contains($file, '/packages/')) {
                $files[$file] = $lines;
            }
        }

        // A file per process, named by pid AND a unique id: children run
        // concurrently in some tests, and a run spawns enough short-lived
        // processes that the operating system recycles pids within it.
        $path = rtrim($lavaCoverageDir, '/') . '/' . getmypid() . '-' . uniqid() . '.json';

        // Written even when empty: a child that ran nothing is a fact the
        // report should be able to count, and an absent file is indistinguishable
        // from a child that never started.
        file_put_contents($path, json_encode($files));
    });
}
