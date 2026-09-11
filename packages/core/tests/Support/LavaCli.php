<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Runs the REAL `bin/lava` as a subprocess against a fixture app.
 *
 * Unit tests prove the console kernel's pieces; only this proves the binary is
 * executable, finds its autoloader, resolves the app directory from the working
 * directory, and puts the envelope on real stdout with the real exit code — the
 * exact thing an agent shells out to.
 *
 * Two things the child gets that a fixture cannot supply for itself, both of
 * them the harness standing in for an installed app:
 *
 *  - `auto_prepend_file` registers the App\ autoloader a real app's
 *    composer.json would provide (see fixture-autoload.php);
 *  - `LAVA_PHPUNIT` points at this repo's PHPUnit, so `lava test` and
 *    `lava check` can run a fixture's suite without a vendor/ directory.
 *
 * The child environment is built explicitly rather than inherited wholesale:
 * LAVA_ENV, LAVA_FEATURE_* and the two variables above are stripped first, so
 * a CI environment can never steer a fixture's flags — the same hermeticity
 * TestApp gives in-process tests.
 */
final class LavaCli
{
    /**
     * @param list<string> $args arguments after the binary
     * @param array<string, string|null> $env extra variables; a null value REMOVES one
     */
    public static function run(array $args, string $appDir, array $env = []): LavaResult
    {
        $process = proc_open(
            self::argv($args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $appDir,
            self::environment($appDir, $env),
        );
        Assert::assertIsResource($process, 'could not start the lava binary');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new LavaResult(proc_close($process), $stdout, $stderr);
    }

    public static function bin(): string
    {
        return dirname(__DIR__, 2) . '/bin/lava';
    }

    public static function autoloader(): string
    {
        return __DIR__ . '/fixture-autoload.php';
    }

    /**
     * Whether this app brings a test suite of its own — what PHPUnit itself
     * looks for when it is started in an app directory.
     */
    public static function declaresASuite(string $appDir): bool
    {
        return is_file($appDir . '/phpunit.xml.dist') || is_file($appDir . '/phpunit.xml');
    }

    /** The repo's own PHPUnit — what the harness lends a fixture. */
    public static function repoPhpUnit(): string
    {
        return dirname(__DIR__, 4) . '/vendor/bin/phpunit';
    }

    /**
     * The exact argv the child runs. An array, not a shell string: nothing here
     * is quoted, escaped, or interpreted, and the process PHP terminates is the
     * one it started rather than a shell wrapping it.
     *
     * @param list<string> $args
     * @return list<string>
     */
    public static function argv(array $args): array
    {
        return array_merge(
            [PHP_BINARY, '-d', 'auto_prepend_file=' . self::autoloader(), self::bin()],
            $args,
        );
    }

    /**
     * @param array<string, string|null> $env
     * @return array<string, string>
     */
    public static function environment(string $appDir, array $env = []): array
    {
        $child = [];
        foreach (getenv() as $name => $value) {
            if (!self::isLavaVar($name)) {
                $child[$name] = $value;
            }
        }

        $child['LAVA_FIXTURE_APP_DIR'] = $appDir;
        // Only for a fixture that declares a suite. A real app gets its runner
        // from its own vendor/, and the harness stands in for exactly that — so
        // an app with no phpunit config keeps the honest `missing_test_runner`
        // instead of PHPUnit's "nothing to run" (bad_test_report).
        if (self::declaresASuite($appDir) && is_file(self::repoPhpUnit())) {
            $child['LAVA_PHPUNIT'] = self::repoPhpUnit();
        }

        foreach ($env as $name => $value) {
            if ($value === null) {
                unset($child[$name]);
                continue;
            }
            $child[$name] = $value;
        }

        return $child;
    }

    private static function isLavaVar(string $name): bool
    {
        return $name === 'LAVA_ENV'
            || $name === 'LAVA_FIXTURE_APP_DIR'
            || $name === 'LAVA_PHPUNIT'
            || str_starts_with($name, 'LAVA_FEATURE_');
    }
}
