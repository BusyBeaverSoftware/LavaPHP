<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

/**
 * Runs code with a controlled process environment, and puts the environment back
 * exactly as it found it.
 *
 * Booting an app PROMOTES config/.env values into the process environment and
 * never takes them back — correct for `lava`, which boots once and exits, and
 * wrong for a test process that boots many times: a value one boot promoted
 * would look like a shell export to the next. So the three sources (`$_ENV`,
 * `$_SERVER`, `getenv()`) are saved and restored together, and `LAVA_ENV` and
 * every `LAVA_FEATURE_*` inherited from the outer shell are cleared first, so a
 * CI setting can never steer what a test sees.
 *
 * Shared by {@see TestApp} and {@see TestConsole}, which make the same promise.
 */
final class IsolatedEnvironment
{
    /**
     * @template T
     * @param array<string, string> $env variables visible while `$work` runs
     * @param \Closure(): T $work
     * @return T
     */
    public static function run(array $env, \Closure $work): mixed
    {
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $realBefore = getenv();

        self::clearLavaEnv();
        foreach ($env as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return $work();
        } finally {
            self::restore($envBefore, $serverBefore, $realBefore);
        }
    }

    /** Clears LAVA_ENV and every LAVA_FEATURE_* from all three environment sources. */
    private static function clearLavaEnv(): void
    {
        foreach ([&$_ENV, &$_SERVER] as &$source) {
            foreach (array_keys($source) as $key) {
                if (is_string($key) && self::isLavaVar($key)) {
                    unset($source[$key]);
                }
            }
        }
        unset($source);
        // getenv() with no arguments is always an array — no false case to guard.
        foreach (array_keys(getenv()) as $key) {
            if (self::isLavaVar($key)) {
                putenv($key); // putenv('NAME') unsets
            }
        }
    }

    /**
     * Puts the three environment sources back exactly as they were.
     *
     * `getenv()` needs two passes. The first visits what is there now: a value
     * the run changed goes back, and a name it added goes. The second visits
     * what was there before, because a name that is gone now is in no list the
     * first pass reads: the `LAVA_ENV` and `LAVA_FEATURE_*` the run cleared, and
     * anything the work unset, which a child process would otherwise inherit
     * the loss of (Lava Notes, R3-B17).
     *
     * `array<mixed>`, not `array<string, string>`: these two are verbatim
     * snapshots of `$_ENV` and `$_SERVER` — whatever PHP's SAPI put in them —
     * and this method's only job is to put them back. A narrower claim would be
     * a promise nothing here keeps, and the analyser is right to reject it:
     * `$_SERVER` genuinely may hold a non-string (`argv`, a nested array under
     * a SAPI that sets one), and the restore is a straight assignment.
     *
     * @param array<mixed> $envBefore
     * @param array<mixed> $serverBefore
     * @param array<string, string> $realBefore
     */
    private static function restore(array $envBefore, array $serverBefore, array $realBefore): void
    {
        $_ENV = $envBefore;
        $_SERVER = $serverBefore;
        foreach (getenv() as $name => $value) {
            if (!array_key_exists($name, $realBefore) || $realBefore[$name] !== $value) {
                putenv(array_key_exists($name, $realBefore) ? "{$name}={$realBefore[$name]}" : $name);
            }
        }
        foreach ($realBefore as $name => $value) {
            if (getenv($name) !== $value) {
                putenv("{$name}={$value}");
            }
        }
    }

    private static function isLavaVar(string $key): bool
    {
        return $key === 'LAVA_ENV' || str_starts_with($key, 'LAVA_FEATURE_');
    }
}
