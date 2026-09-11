<?php

declare(strict_types=1);

namespace Lava\Core\Config;

use Lava\Core\Boot\App;
use Lava\Core\Problem\MissingEnvVar;

/**
 * The one rule for "a declared required variable has no value".
 *
 * `lava env` reports it and `lava check --strict` escalates it, so both ask
 * here rather than each deciding for itself — the same reason `lava test` and
 * `lava check` share {@see \Lava\Core\Console\TestRun}. Boot cannot answer it:
 * a variable nothing reads is never resolved, so the app boots green while a
 * required value is missing, and only a sweep over every declaration notices.
 */
final class EnvAudit
{
    /**
     * @return list<MissingEnvVar> one per unset required variable, in the order
     *                              {@see App::envVars()} declares them
     */
    public static function missing(App $app): array
    {
        $missing = [];
        foreach ($app->envVars() as $entry) {
            $var = $entry['var'];
            if ($var === null || !$var->required) {
                continue;
            }
            if (self::value($app, $entry['name']) !== null) {
                continue;
            }
            $missing[] = MissingEnvVar::of($entry['name'], $entry['by'] ?? 'the app');
        }

        return $missing;
    }

    /**
     * A name's live value, from the process environment or config/.env.
     *
     * The real environment wins because boot promoted the file into it, and
     * `App::$dotEnv` is the fallback for a boot that was asked not to promote
     * (an already-exported name keeps its own value).
     */
    public static function value(App $app, string $name): ?string
    {
        return ProcessEnv::real($name) ?? $app->dotEnv[$name] ?? null;
    }
}
