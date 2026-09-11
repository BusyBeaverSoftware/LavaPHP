<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\SourceLocation;

/**
 * Runs app/Services.php — THE wiring file, the only place app services are
 * registered. Missing file means "none of that": zero-config apps are valid.
 * Problems the wiring code throws (duplicate id, unregistered dependency) are
 * caught by the kernel and rendered with the wiring file's own file:line.
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
    }
}