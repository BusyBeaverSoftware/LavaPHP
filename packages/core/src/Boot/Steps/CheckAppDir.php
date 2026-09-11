<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Problem\NotAnApp;

/**
 * The first step, and the only one that asks whether there is an app here at
 * all. Every user-authored artifact in conventions.md is optional, so without
 * this check booting the wrong directory yields a silent empty app; see
 * {@see NotAnApp} for why that is worse than a failure.
 *
 * The signal is deliberately generous — ANY ONE of the three is enough. A
 * zero-config app is `public/index.php` and nothing else, a config-only app is
 * legitimate (`bad-flags-app` in the fixtures is exactly that), and a route-only
 * app needs neither. Requiring a specific file would fail real apps; requiring
 * all three would fail most of them.
 */
final class CheckAppDir implements BootStep
{
    /** @var list<string> the artifacts that mark a directory as an app root */
    private const MARKERS = ['app', 'config', 'public/index.php'];

    public function run(BootCtx $ctx): void
    {
        foreach (self::MARKERS as $marker) {
            if (file_exists($ctx->appDir . '/' . $marker)) {
                return;
            }
        }

        $ctx->problems->add(NotAnApp::at($ctx->appDir, self::MARKERS));
    }
}
