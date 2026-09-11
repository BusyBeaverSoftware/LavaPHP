<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Config\Config;
use Lava\Core\Config\ConfigFile;

/**
 * Loads the core config files (config/app.php, config/logging.php) into an
 * immutable Config with per-key provenance, then resolves the environment
 * name: LAVA_ENV (real env or .env) wins, then config/app.php 'env', then
 * 'dev'. Pack-declared config files are loaded by {@see LoadPackConfig},
 * through the same {@see ConfigFile} loader this uses.
 */
final class LoadConfig implements BootStep
{
    private const FILES = ['app', 'logging'];

    public function run(BootCtx $ctx): void
    {
        $config = new Config();
        foreach (self::FILES as $name) {
            $config = ConfigFile::load(
                $config,
                $ctx->configPath($name . '.php'),
                $name,
                "config/{$name}.php",
                $ctx->problems,
            );
        }
        $ctx->config = $config;

        $env = $ctx->envValue('LAVA_ENV');
        if ($env === null && $config->has('app.env')) {
            $env = $config->string('app.env', 'dev');
        }
        $ctx->env = $env ?? 'dev';
    }
}