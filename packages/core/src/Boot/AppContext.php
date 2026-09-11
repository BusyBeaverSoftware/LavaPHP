<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Config\Config;
use Lava\Core\Features\Features;

/**
 * What user wiring code (app/Services.php, module register()) receives, so
 * config and environment reach factories as visible arguments instead of
 * being smuggled through the container.
 */
final readonly class AppContext
{
    public function __construct(
        public string $appDir,
        public string $env,
        public Config $config,
        public Features $features,
    ) {
    }
}