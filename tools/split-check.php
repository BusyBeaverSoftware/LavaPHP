#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Publishing, rehearsed on this machine.
 *
 * Every package under packages/ becomes a git repository of its own — what
 * .github/workflows/split.yml pushes to a mirror — tagged with the next patch
 * version. A Composer home that lists those repositories stands in for
 * Packagist, which reads the same thing: a repository whose root holds
 * composer.json, and its tags. A consumer then runs exactly the documented
 * commands against it:
 *
 *     composer create-project lavaphp/app consumer
 *     composer require lavaphp/db lavaphp/validate lavaphp/view lavaphp/http-client lavaphp/events
 *     vendor/bin/lava check --strict
 *
 * and every lavaphp/* package in the consumer's lock has to have come from its
 * mirror, at the rehearsal version. Nothing leaves the machine and nothing is
 * published.
 *
 * Run it with `composer check:split`. CI's `publish-rehearsal` job runs it too.
 *
 * ── What it does not rehearse ───────────────────────────────────────────────
 *
 * The mirrors are built from the working tree, not from history, so what is
 * rehearsed is the set of manifests about to be committed. The workflow's own
 * mechanic, `git subtree split` over committed history, cannot see uncommitted
 * files and is not repeated here. Packagist's own behaviour — its webhook, its
 * cache — is outside anything a local run can show.
 */

require __DIR__ . '/lib/workspace.php';

use function Lava\Tools\copyFreshClone;
use function Lava\Tools\monorepoVersion;
use function Lava\Tools\must;
use function Lava\Tools\readJsonObject;
use function Lava\Tools\removeScratch;
use function Lava\Tools\run;
use function Lava\Tools\scratch;
use function Lava\Tools\writeJsonObject;

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, 'split-check: cannot resolve the repository root from ' . __DIR__ . "\n");
    exit(1);
}

$packages = ['core', 'db', 'validate', 'view', 'http-client', 'events', 'app'];
$packs = ['lavaphp/db', 'lavaphp/validate', 'lavaphp/view', 'lavaphp/http-client', 'lavaphp/events'];

$version = monorepoVersion($root);
$parts = explode('.', $version);
$tag = $parts[0] . '.' . ($parts[1] ?? '0') . '.' . (((int) ($parts[2] ?? '0')) + 1);

// The real Composer cache, so the rehearsal re-downloads nothing it already has.
[$cacheCode, $cacheOutput] = run(['composer', 'config', '--global', 'cache-dir'], $root);
$cacheDir = $cacheCode === 0 ? trim($cacheOutput) : '';

$scratch = scratch('split-check');
$exit = 0;

try {
    echo "split-check: rehearsing {$tag} from the working tree\n";

    $repositories = [];
    foreach ($packages as $package) {
        $mirror = "{$scratch}/mirrors/lava-{$package}";
        copyFreshClone($root, "packages/{$package}", $mirror, asRoot: true);

        if (array_key_exists('repositories', readJsonObject("{$mirror}/composer.json"))) {
            throw new \RuntimeException(
                "packages/{$package}/composer.json declares repositories, which split.yml refuses to publish.",
            );
        }

        $git = ['git', '-c', 'user.name=split-check', '-c', 'user.email=split-check@localhost'];
        must([...$git, 'init', '-q', '-b', 'main'], $mirror);
        must([...$git, 'add', '-A'], $mirror);
        must([...$git, 'commit', '-q', '-m', "packages/{$package}, as the split publishes it"], $mirror);
        must([...$git, 'tag', $tag], $mirror);
        $repositories[] = ['type' => 'vcs', 'url' => $mirror];
        echo "  mirror   lava-{$package} tagged {$tag}\n";
    }

    // Packagist, stood in for: a Composer home whose config lists the mirrors.
    // The consumer's own composer.json never mentions them, as it would not
    // for a package on Packagist.
    $home = "{$scratch}/composer-home";
    if (!mkdir($home, 0o755, true)) {
        throw new \RuntimeException("cannot create {$home}");
    }
    writeJsonObject("{$home}/config.json", ['repositories' => $repositories]);
    $env = ['COMPOSER_HOME' => $home];
    if ($cacheDir !== '') {
        $env['COMPOSER_CACHE_DIR'] = $cacheDir;
    }

    must(['composer', 'create-project', 'lavaphp/app', 'consumer', $tag, '--no-interaction', '--no-progress'], $scratch, $env);
    $consumer = "{$scratch}/consumer";
    echo "  consumer composer create-project lavaphp/app: ok\n";

    must(['composer', 'require', ...$packs, '--no-interaction', '--no-progress'], $consumer, $env);
    echo '  consumer composer require ' . implode(' ', $packs) . ": ok\n";

    $lock = readJsonObject("{$consumer}/composer.lock");
    $entries = $lock['packages'] ?? null;
    $installed = [];
    foreach (is_array($entries) ? $entries : [] as $entry) {
        if (!is_array($entry) || !is_string($entry['name'] ?? null) || !str_starts_with($entry['name'], 'lavaphp/')) {
            continue;
        }
        $source = is_array($entry['source'] ?? null) ? ($entry['source']['url'] ?? null) : null;
        $installed[$entry['name']] = [
            'version' => is_string($entry['version'] ?? null) ? $entry['version'] : '',
            'source' => is_string($source) ? $source : '',
        ];
    }
    ksort($installed);

    $expected = ['lavaphp/core', ...$packs];
    sort($expected);
    if (array_keys($installed) !== $expected) {
        throw new \RuntimeException(
            'the consumer installed ' . implode(', ', array_keys($installed)) . '; expected ' . implode(', ', $expected),
        );
    }
    foreach ($installed as $name => $record) {
        if ($record['version'] !== $tag || !str_starts_with($record['source'], "{$scratch}/mirrors/")) {
            throw new \RuntimeException(
                "{$name} did not come from its mirror at {$tag}: got {$record['version']} from {$record['source']}",
            );
        }
        echo "  locked   {$name} {$record['version']} from mirrors/" . basename($record['source']) . "\n";
    }

    must([PHP_BINARY, 'vendor/bin/lava', 'check', '--strict'], $consumer);
    echo "  consumer vendor/bin/lava check --strict: ok\n";
    echo "split-check: an app built from the mirrors alone installs and checks green.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'split-check: ' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    removeScratch($scratch);
}

exit($exit);
