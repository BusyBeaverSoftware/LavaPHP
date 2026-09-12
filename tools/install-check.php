#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The install gate, on this machine: each publishable package and each app,
 * copied out of the working tree as a fresh clone would have it, and installed
 * for real.
 *
 * Run it with `composer check:install`, or name the targets:
 * `composer check:install -- db app`. Targets: core, db, validate, view,
 * http-client, app (the skeleton), demo, blog.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * `composer verify` and `lava check` resolve against whatever vendor/ is already
 * on disk, so a manifest change is new code judged by an old resolution graph.
 * Three false greens came from exactly that (DECISIONS.md 231). CI caught them
 * with fresh installs; nothing local did, so the only way to learn a manifest
 * was wrong was a push.
 *
 * ── What each target gets ───────────────────────────────────────────────────
 *
 * A package: `composer validate --strict` on its manifest AS PUBLISHED, then a
 * fresh install. The manifest has no `repositories` block — a published package
 * cannot carry one (DECISIONS.md 229) — so the copy is given path repositories
 * to its sibling packages: the monorepo standing in for Packagist, added to the
 * copy and never to the file in the repository.
 *
 * The skeleton: the same, then `lava map --check` and `lava check --strict`,
 * because it is an app as well as a package.
 *
 * An app (demo, blog): its own manifest already points at ../../packages, so it
 * is validated and installed as it stands, then mapped and checked.
 *
 * CI's `isolated-install` and `skeleton` jobs run this script, so a green here
 * is the green CI will see.
 */

require __DIR__ . '/lib/workspace.php';

use function Lava\Tools\copyFreshClone;
use function Lava\Tools\monorepoVersion;
use function Lava\Tools\readJsonObject;
use function Lava\Tools\removeScratch;
use function Lava\Tools\scratch;
use function Lava\Tools\step;
use function Lava\Tools\withSiblingRepositories;
use function Lava\Tools\writeJsonObject;

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, 'install-check: cannot resolve the repository root from ' . __DIR__ . "\n");
    exit(1);
}

$targets = [
    'core' => ['dir' => 'packages/core', 'kind' => 'package', 'env' => []],
    'db' => ['dir' => 'packages/db', 'kind' => 'package', 'env' => []],
    'validate' => ['dir' => 'packages/validate', 'kind' => 'package', 'env' => []],
    'view' => ['dir' => 'packages/view', 'kind' => 'package', 'env' => []],
    'http-client' => ['dir' => 'packages/http-client', 'kind' => 'package', 'env' => []],
    'app' => ['dir' => 'packages/app', 'kind' => 'skeleton', 'env' => []],
    'demo' => ['dir' => 'apps/demo', 'kind' => 'app', 'env' => []],
    // The blog does not boot without a session secret. This one signs nothing
    // outside a scratch directory that is deleted when its check ends.
    'blog' => ['dir' => 'apps/blog', 'kind' => 'app', 'env' => ['SESSION_SECRET' => 'install-check-only-not-a-secret']],
];

// Arguments, read from the superglobal and narrowed: `composer check:install --
// db app` arrives as argv, and `--` is Composer's separator, not a target.
$arguments = $_SERVER['argv'] ?? [];
$names = [];
foreach (is_array($arguments) ? array_slice($arguments, 1) : [] as $argument) {
    if (is_string($argument) && $argument !== '--') {
        $names[] = $argument;
    }
}
if ($names === []) {
    $names = array_keys($targets);
}
foreach ($names as $name) {
    if (!array_key_exists($name, $targets)) {
        fwrite(STDERR, "install-check: no target named '{$name}'. Targets: " . implode(', ', array_keys($targets)) . "\n");
        exit(2);
    }
}

$version = monorepoVersion($root);
$failures = 0;
echo 'install-check: ' . count($names) . " target(s), lava/* from the working tree at {$version}\n";

foreach ($names as $name) {
    $target = $targets[$name];
    $started = microtime(true);
    $scratch = scratch('install-check');
    $done = [];
    $failure = null;

    try {
        // Every library package is copied, so a path repository always has a
        // sibling to point at; the target is copied beside them in its own place.
        foreach (['core', 'db', 'validate', 'view', 'http-client'] as $package) {
            copyFreshClone($root, "packages/{$package}", $scratch);
        }
        copyFreshClone($root, $target['dir'], $scratch);
        $directory = $scratch . '/' . $target['dir'];

        $failure = step('validate', ['composer', 'validate', '--strict', '--no-check-lock'], $directory, $target['env'], $done);

        if ($failure === null && $target['kind'] !== 'app') {
            $manifest = $directory . '/composer.json';
            writeJsonObject($manifest, withSiblingRepositories(readJsonObject($manifest), $version));
        }

        $failure ??= step('install', ['composer', 'install', '--no-interaction', '--no-progress'], $directory, $target['env'], $done);

        if ($target['kind'] !== 'package') {
            $failure ??= step('map', [PHP_BINARY, 'vendor/bin/lava', 'map', '--check'], $directory, $target['env'], $done);
            $failure ??= step('check', [PHP_BINARY, 'vendor/bin/lava', 'check', '--strict'], $directory, $target['env'], $done);
        }
    } catch (\Throwable $error) {
        $failure = 'the check itself failed: ' . $error->getMessage();
    } finally {
        removeScratch($scratch);
    }

    $passed = implode(' · ', array_map(static fn (string $label): string => "{$label} ok", $done));
    printf("  %-12s %-46s %5.1fs\n", $name, $passed, microtime(true) - $started);
    if ($failure !== null) {
        $failures++;
        echo preg_replace('/^/m', '      ', $failure) ?? $failure, "\n";
    }
}

if ($failures > 0) {
    fwrite(STDERR, "install-check: {$failures} of " . count($names) . " target(s) failed.\n");
    exit(1);
}

echo "install-check: every target installed fresh and passed.\n";
