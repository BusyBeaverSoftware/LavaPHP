<?php

declare(strict_types=1);

namespace Lava\Tools;

/*
 * What tools/install-check.php and tools/split-check.php share: running a
 * command without a shell, copying exactly the files a fresh clone would have,
 * and removing the scratch directories afterwards. The tools leave nothing in
 * the temp directory, for the same reason the test harnesses do not.
 */

/**
 * Run a command — an argument list, never a shell string — with its output
 * captured.
 *
 * @param list<string> $command
 * @param array<string, string> $env merged over the inherited environment
 * @return array{int, string} the exit code, and stdout and stderr interleaved
 */
function run(array $command, string $cwd, array $env = []): array
{
    // Both streams append to one file instead of a pipe: a child that fills a
    // pipe nobody is reading yet blocks forever, and Composer writes a great
    // deal to stderr. Two append-mode descriptors on one file interleave the
    // way a terminal would.
    $log = tempnam(sys_get_temp_dir(), 'lava-run-');
    if ($log === false) {
        return [127, 'could not create a file for the output of: ' . implode(' ', $command)];
    }

    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $cwd,
        [...getenv(), ...$env],
    );
    if (!is_resource($process)) {
        unlink($log);

        return [127, 'could not start: ' . implode(' ', $command)];
    }

    $code = proc_close($process);
    $output = file_get_contents($log);
    unlink($log);

    return [$code, $output === false ? '' : $output];
}

/** The last lines of a command's output — enough to diagnose, short enough to read. */
function tail(string $output, int $lines = 25): string
{
    return implode("\n", array_slice(explode("\n", rtrim($output)), -$lines));
}

/**
 * One step of a check: on success its label is added to `$done` and null comes
 * back; on failure, the reason, with the end of the command's output.
 *
 * @param list<string> $command
 * @param array<string, string> $env
 * @param list<string> $done
 */
function step(string $label, array $command, string $directory, array $env, array &$done): ?string
{
    [$code, $output] = run($command, $directory, $env);
    if ($code !== 0) {
        return "{$label} failed (exit {$code}):\n" . tail($output);
    }
    $done[] = $label;

    return null;
}

/**
 * Run a command that has to succeed, and return its output.
 *
 * @param list<string> $command
 * @param array<string, string> $env
 * @throws \RuntimeException naming the command and the end of its output
 */
function must(array $command, string $directory, array $env = []): string
{
    [$code, $output] = run($command, $directory, $env);
    if ($code !== 0) {
        throw new \RuntimeException(implode(' ', $command) . " failed (exit {$code}):\n" . tail($output));
    }

    return $output;
}

/**
 * The files a fresh clone would have under `$path`: tracked ones, plus new ones
 * not yet added, and never ignored ones — so no local vendor/, lock or .env can
 * make a check pass that a clone would fail.
 *
 * @return list<string> paths relative to the repository root
 */
function freshCloneFiles(string $root, string $path): array
{
    [$code, $output] = run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--', $path], $root);
    if ($code !== 0) {
        throw new \RuntimeException("git ls-files {$path} failed: {$output}");
    }

    $files = [];
    foreach (explode("\n", $output) as $line) {
        // --cached also lists a tracked file deleted in the working tree, which
        // the next commit's clone would not have either.
        if ($line !== '' && is_file($root . '/' . $line)) {
            $files[] = $line;
        }
    }

    return $files;
}

/**
 * Copy what a fresh clone has under `$path` into `$to`, each file keeping its
 * path relative to the repository root — or relative to `$path` itself when
 * `$asRoot`, which is the shape of a split mirror.
 */
function copyFreshClone(string $root, string $path, string $to, bool $asRoot = false): void
{
    $prefix = rtrim($path, '/') . '/';
    foreach (freshCloneFiles($root, $path) as $file) {
        $relative = $asRoot && str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
        $target = $to . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException("cannot create {$directory}");
        }
        if (!copy($root . '/' . $file, $target)) {
            throw new \RuntimeException("cannot copy {$file}");
        }
        // Keep the executable bit: packages/core/bin/lava is run directly.
        $mode = fileperms($root . '/' . $file);
        if ($mode !== false) {
            chmod($target, $mode & 0o777);
        }
    }
}

/** A new scratch directory under the system temp directory, named for its tool. */
function scratch(string $tool): string
{
    $directory = sys_get_temp_dir() . '/lava-' . $tool . '-' . bin2hex(random_bytes(4));
    if (!mkdir($directory, 0o755, true)) {
        throw new \RuntimeException("cannot create {$directory}");
    }

    return $directory;
}

/**
 * Remove a scratch directory made by {@see scratch()}, and refuse anything else:
 * a tool that deletes trees must not be one wrong argument away from deleting
 * the wrong one.
 */
function removeScratch(string $directory): void
{
    if (!str_starts_with($directory, sys_get_temp_dir() . '/lava-')) {
        throw new \LogicException("refusing to remove {$directory}: it is not a scratch directory");
    }
    if (!is_dir($directory)) {
        return;
    }

    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        if (!$item instanceof \SplFileInfo) {
            continue;
        }
        // A symlink is removed, never followed: vendor/lava/* link to sibling
        // copies inside this same directory.
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

/**
 * A JSON object from disk, keeping only string keys.
 *
 * @return array<string, mixed>
 */
function readJsonObject(string $file): array
{
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new \RuntimeException("cannot read {$file}");
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new \RuntimeException("{$file} does not hold a JSON object");
    }

    $object = [];
    foreach ($decoded as $key => $value) {
        if (is_string($key)) {
            $object[$key] = $value;
        }
    }

    return $object;
}

/** @param array<string, mixed> $object */
function writeJsonObject(string $file, array $object): void
{
    $json = json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($file, $json . "\n") === false) {
        throw new \RuntimeException("cannot write {$file}");
    }
}

/**
 * The version the monorepo pins its packages at: what the root manifest's path
 * repository tells Composer `lava/core` is.
 */
function monorepoVersion(string $root): string
{
    $repositories = readJsonObject($root . '/composer.json')['repositories'] ?? null;
    $core = is_array($repositories) ? ($repositories['lava/core'] ?? null) : null;
    $options = is_array($core) ? ($core['options'] ?? null) : null;
    $versions = is_array($options) ? ($options['versions'] ?? null) : null;
    $version = is_array($versions) ? ($versions['lava/core'] ?? null) : null;

    if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
        throw new \RuntimeException('composer.json does not pin lava/core at a version in its path repository');
    }

    return $version;
}

/**
 * The manifest with path repositories to the sibling packages for every `lava/*`
 * it requires — the monorepo standing in for Packagist, for a copy of one
 * package. Never written back to the package in the repository.
 *
 * @param array<string, mixed> $manifest
 * @return array<string, mixed>
 */
function withSiblingRepositories(array $manifest, string $version): array
{
    $repositories = [];
    foreach (['require', 'require-dev'] as $section) {
        $requires = $manifest[$section] ?? null;
        if (!is_array($requires)) {
            continue;
        }
        foreach (array_keys($requires) as $package) {
            if (is_string($package) && str_starts_with($package, 'lava/')) {
                $repositories[$package] = [
                    'type' => 'path',
                    'url' => '../' . substr($package, strlen('lava/')),
                    'options' => ['versions' => [$package => $version]],
                ];
            }
        }
    }

    if ($repositories !== []) {
        $manifest['repositories'] = $repositories;
    }

    return $manifest;
}
