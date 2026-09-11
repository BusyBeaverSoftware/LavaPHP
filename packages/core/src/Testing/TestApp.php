<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Boot\Kernel;

/**
 * Boots fixture apps hermetically:
 *
 *  - the process environment is exactly restored when boot returns (even the
 *    variables a fixture's config/.env promoted during boot);
 *  - LAVA_ENV / LAVA_FEATURE_* from the outer environment are cleared for the
 *    boot, so CI environment settings can never steer a fixture's flags;
 *  - the given overrides are visible during boot only.
 *
 * Fixture apps live in tests/fixtures/apps/<name>/ and are structurally
 * identical to real apps. Their App\ classes autoload from the fixture's own
 * app/ directory (real apps get that mapping from composer.json); the loader
 * stays registered for the process, so fixture apps must use distinct class
 * AND function names from each other — two apps both declaring
 * App\Http\health() is an uncatchable redeclare fatal for the whole process.
 *
 * The boot steps re-execute the app files they require (app/Modules.php,
 * app/Services.php, app/Routes.php, app/Middleware.php) on every boot — a
 * real process boots once, tests boot many times. Those files must therefore
 * contain only the returned closure/array: classes and named functions
 * belong in autoloaded files or files loaded with require_once at the top
 * (the same rule the conventions set for function handler files).
 */
final class TestApp
{
    /** @var array<string, true> app dirs whose App\ autoloader is already registered */
    private static array $autoloaded = [];

    /**
     * @param array<string, string> $env variables visible during boot, e.g. ['LAVA_ENV' => 'prod']
     */
    public static function boot(string $appDir, array $env = []): App|BootFailure
    {
        $appDir = rtrim($appDir, '/');
        self::autoloadFor($appDir);

        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $realBefore = is_array(getenv()) ? getenv() : [];

        self::clearLavaEnv();
        foreach ($env as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return Kernel::boot($appDir);
        } finally {
            self::restoreEnv($envBefore, $serverBefore, $realBefore);
        }
    }

    /** Boots a fixture app from tests/fixtures/apps/&lt;name&gt; with the same hermetic contract. */
    public static function bootFixture(string $name, array $env = []): App|BootFailure
    {
        return self::boot(self::fixturePath($name), $env);
    }

    public static function fixturePath(string $name): string
    {
        $dir = dirname(__DIR__, 2) . '/tests/fixtures/apps/' . $name;
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("No fixture app named '{$name}' under tests/fixtures/apps/.");
        }
        return $dir;
    }

    private static function autoloadFor(string $appDir): void
    {
        if (isset(self::$autoloaded[$appDir])) {
            return;
        }
        self::$autoloaded[$appDir] = true;
        spl_autoload_register(static function (string $class) use ($appDir): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }
            $file = $appDir . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
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
        $real = getenv();
        if (is_array($real)) {
            foreach (array_keys($real) as $key) {
                if (is_string($key) && self::isLavaVar($key)) {
                    putenv((string) $key); // putenv('NAME') unsets
                }
            }
        }
    }

    /**
     * @param array<string, string> $envBefore
     * @param array<string, string> $serverBefore
     * @param array<string, string> $realBefore
     */
    private static function restoreEnv(array $envBefore, array $serverBefore, array $realBefore): void
    {
        $_ENV = $envBefore;
        $_SERVER = $serverBefore;
        $realAfter = getenv();
        if (!is_array($realAfter)) {
            return;
        }
        foreach ($realAfter as $name => $value) {
            if (!array_key_exists($name, $realBefore) || $realBefore[$name] !== $value) {
                putenv(array_key_exists($name, $realBefore) ? "{$name}={$realBefore[$name]}" : (string) $name);
            }
        }
    }

    private static function isLavaVar(string $key): bool
    {
        return $key === 'LAVA_ENV' || str_starts_with($key, 'LAVA_FEATURE_');
    }
}