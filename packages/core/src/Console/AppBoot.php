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
    /**
     * @param string|null $env the --env override; null means "use the real environment"
     */
    public static function boot(string $appDir, ?string $env): App|BootFailure
    {
        return $env === null ? Kernel::boot($appDir) : self::bootWithEnv($appDir, $env);
    }

    /**
     * Booting under a different environment is how an agent asks "is this flag
     * on in prod?" without editing config, so the override is applied to all
     * three ways PHP reads the environment and undone in a finally.
     */
    private static function bootWithEnv(string $appDir, string $env): App|BootFailure
    {
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $realBefore = getenv();

        $_ENV['LAVA_ENV'] = $env;
        $_SERVER['LAVA_ENV'] = $env;
        putenv("LAVA_ENV={$env}");

        try {
            return Kernel::boot($appDir);
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
