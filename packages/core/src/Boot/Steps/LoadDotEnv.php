<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Config\DotEnv;

/**
 * Loads config/.env (if present) into the real environment — without ever
 * overriding variables that are already set. Missing file means "nothing to
 * load", not a problem: apps boot zero-config.
 */
final class LoadDotEnv implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        $file = $ctx->configPath('.env');
        if (!is_file($file)) {
            return;
        }
        $ctx->dotEnv = DotEnv::load($file, $ctx->problems);
        foreach ($ctx->dotEnv as $name => $value) {
            if ($ctx->realEnv($name) === null) {
                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }
}