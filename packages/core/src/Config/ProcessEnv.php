<?php

declare(strict_types=1);

namespace Lava\Core\Config;

/**
 * Reads the REAL process environment — never config/.env. Boot and the CLI
 * both ask here, so `lava env`'s "where did this come from?" answer is the
 * same one boot acted on. A command that re-derived this could disagree with
 * the app it is describing, which is the one thing a diagnostic must not do.
 */
final class ProcessEnv
{
    public static function real(string $name): ?string
    {
        foreach ([$_ENV, $_SERVER] as $source) {
            if (isset($source[$name]) && is_string($source[$name])) {
                return $source[$name];
            }
        }
        $value = getenv($name);
        return $value === false ? null : $value;
    }
}
