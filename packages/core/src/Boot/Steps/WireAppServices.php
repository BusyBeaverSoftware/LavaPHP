<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Config\EnvVar;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\SourceLocation;

/**
 * Runs app/Services.php — THE wiring file, the only place app services are
 * registered. Missing file means "none of that": zero-config apps are valid.
 * Problems the wiring code throws (duplicate id, unregistered dependency) are
 * caught by the kernel and rendered with the wiring file's own file:line.
 *
 * The one post-condition checked here is `app.env_vars`: a list of EnvVar is
 * what `lava env` reads, and validating it at boot means the command can trust
 * its shape instead of re-checking it on every run.
 */
final class WireAppServices implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        if ($ctx->container === null || $ctx->appContext === null) {
            return; // a fatal upstream already stopped the chain
        }

        $file = $ctx->appPath('Services.php');
        if (!is_file($file)) {
            return;
        }
        $wiring = require $file;
        if (!is_callable($wiring)) {
            $ctx->problems->add(new InvalidConfig(
                'app/Services.php must return a callable that registers services.',
                'End the file with: return function (Container $c, AppContext $ctx): void { … };',
                ['file' => 'app/Services.php'],
                SourceLocation::of($file, 1),
            ));
            return;
        }
        $wiring($ctx->container, $ctx->appContext);
        self::validateEnvVars($ctx, $file);
    }

    /**
     * `app.env_vars` is optional; when present it must be a list of EnvVar.
     * Anything else is a Problem rather than a silent skip — `lava env` would
     * otherwise show an empty table for an app that clearly declared things.
     */
    private static function validateEnvVars(BootCtx $ctx, string $file): void
    {
        $container = $ctx->container;
        if ($container === null || !$container->has(EnvVar::CONTAINER_ID)) {
            return;
        }
        $declared = $container->get(EnvVar::CONTAINER_ID);
        if (!is_array($declared)) {
            $ctx->problems->add(new InvalidConfig(
                'The value registered under ' . EnvVar::CONTAINER_ID . ' is '
                    . get_debug_type($declared) . ', not a list of EnvVar.',
                "Register it as: \$c->value(EnvVar::CONTAINER_ID, [EnvVar::required('DATABASE_URL', '…')]);",
                ['id' => EnvVar::CONTAINER_ID, 'got' => get_debug_type($declared)],
                SourceLocation::of($file, 1),
            ));
            return;
        }
        foreach ($declared as $index => $entry) {
            if (!$entry instanceof EnvVar) {
                $ctx->problems->add(new InvalidConfig(
                    'Entry ' . $index . ' of ' . EnvVar::CONTAINER_ID . ' is '
                        . get_debug_type($entry) . ', not an EnvVar.',
                    "Build every entry with EnvVar::required('NAME', '…') or EnvVar::optional('NAME', '…').",
                    ['id' => EnvVar::CONTAINER_ID, 'index' => $index, 'got' => get_debug_type($entry)],
                    SourceLocation::of($file, 1),
                ));
            }
        }
    }
}