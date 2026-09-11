<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Config\Config;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\SourceLocation;

/**
 * Loads the core config files (config/app.php, config/logging.php) into an
 * immutable Config with per-key provenance, then resolves the environment
 * name: LAVA_ENV (real env or .env) wins, then config/app.php 'env', then
 * 'dev'. Pack-declared config files are added by the module system.
 */
final class LoadConfig implements BootStep
{
    private const FILES = ['app', 'logging'];

    public function run(BootCtx $ctx): void
    {
        $config = new Config();
        foreach (self::FILES as $name) {
            $file = $ctx->configPath($name . '.php');
            if (!is_file($file)) {
                continue;
            }
            $loaded = require $file;
            if (!is_array($loaded)) {
                $ctx->problems->add(new InvalidConfig(
                    "config/{$name}.php must return an array of config values.",
                    "End the file with: return [ … ]; — config keys become '{$name}.<key>'.",
                    ['file' => "config/{$name}.php"],
                    SourceLocation::of($file, 1),
                ));
                continue;
            }
            $config = $this->absorb($config, $name, $loaded, "config/{$name}.php", $ctx);
        }
        $ctx->config = $config;

        $env = $ctx->envValue('LAVA_ENV');
        if ($env === null && $config->has('app.env')) {
            $env = $config->string('app.env', 'dev');
        }
        $ctx->env = $env ?? 'dev';
    }

    /**
     * @param array<mixed> $loaded
     */
    private function absorb(Config $config, string $name, array $loaded, string $fromFile, BootCtx $ctx): Config
    {
        foreach ($loaded as $key => $value) {
            if (!is_string($key)) {
                $ctx->problems->add(new InvalidConfig(
                    "config/{$name}.php has a non-string key (" . get_debug_type($key) . ").",
                    "Use string keys: return ['base_url' => …] — they become '{$name}.<key>'.",
                    ['file' => $fromFile, 'key' => $key],
                ));
                continue;
            }
            $config = $config->with("{$name}.{$key}", $value, $fromFile);
        }
        return $config;
    }
}