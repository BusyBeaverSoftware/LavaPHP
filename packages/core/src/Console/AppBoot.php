<?php

declare(strict_types=1);

namespace Lava\Core\Console;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Boot\Kernel;

/**
 * Boots an app on behalf of a command. Every app command goes through here, so
 * `--env` means the same thing everywhere and the process environment is
 * restored on every path — a command must never leave LAVA_ENV mutated behind
 * it for whatever runs next in the same process.
 */
final class AppBoot
{
    /** @var array<string, mixed> the services a TestConsole run substitutes, id => value */
    private static array $replace = [];

    /**
     * @param string|null $env the --env override; null means "use the real environment"
     * @param bool $allPacksEnabled see {@see forMap()}; false for every ordinary command
     */
    public static function boot(string $appDir, ?string $env, bool $allPacksEnabled = false): App|BootFailure
    {
        return $env === null
            ? Kernel::boot($appDir, self::$replace, $allPacksEnabled)
            : self::bootWithEnv($appDir, $env, $allPacksEnabled);
    }

    /**
     * The app whose declarations AGENTS.md describes: this one, or a second boot
     * with every installed pack's gate treated as on.
     *
     * The map lists what an app declares, never resolved state, which is what
     * makes the file safe to commit and its fingerprint worth comparing. A pack's
     * gate is resolved state: with it off, the pack registers nothing, so a map
     * compiled from that boot lost the pack's services, commands and routes and
     * read stale on every machine whose environment differed — `lava check
     * --strict` failed on a deploy that changed nothing (Lava Notes, R3-B11).
     *
     * A second boot only when one is needed: an app with every installed pack
     * enabled already describes itself, and re-booting it would be work with no
     * answer to show for it.
     */
    public static function forMap(App $app, ?string $env): App|BootFailure
    {
        foreach ($app->moduleRefs as $ref) {
            if (!isset($app->modules[$ref->moduleClass])) {
                return self::boot($app->appDir, $env, allPacksEnabled: true);
            }
        }

        return $app;
    }

    /**
     * Runs `$work` with `$replace` applied to every boot inside it, and puts
     * back what was there before, however `$work` ends.
     *
     * For {@see \Lava\Core\Testing\TestConsole} only. A command's contract is
     * `run(IO, Args, string $appDir)` and an app command boots the app itself,
     * so a test's substitutes cannot reach that boot as an argument; they reach
     * it for the length of one in-process run, the way IsolatedEnvironment
     * scopes the environment. `bin/lava` never calls this, so nothing an app
     * writes can add an entry ({@see Kernel::boot()}).
     *
     * @internal
     * @template T
     * @param array<string, mixed> $replace id => value, as TestApp::boot() takes them
     * @param \Closure(): T $work
     * @return T
     */
    public static function replacing(array $replace, \Closure $work): mixed
    {
        $before = self::$replace;
        self::$replace = $replace;

        try {
            return $work();
        } finally {
            self::$replace = $before;
        }
    }

    /**
     * Booting under a different environment is how an agent asks "is this flag
     * on in prod?" without editing config, so the override is applied to all
     * three ways PHP reads the environment and undone in a finally.
     */
    private static function bootWithEnv(string $appDir, string $env, bool $allPacksEnabled = false): App|BootFailure
    {
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $realBefore = getenv();

        $_ENV['LAVA_ENV'] = $env;
        $_SERVER['LAVA_ENV'] = $env;
        putenv("LAVA_ENV={$env}");

        try {
            return Kernel::boot($appDir, self::$replace, $allPacksEnabled);
        } finally {
            $_ENV = $envBefore;
            $_SERVER = $serverBefore;
            foreach (getenv() as $name => $value) {
                if (!array_key_exists($name, $realBefore) || $realBefore[$name] !== $value) {
                    putenv(array_key_exists($name, $realBefore) ? "{$name}={$realBefore[$name]}" : $name);
                }
            }
        }
    }
}
