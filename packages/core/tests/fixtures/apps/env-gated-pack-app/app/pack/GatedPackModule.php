<?php

declare(strict_types=1);

// A pack whose gate is set PER ENVIRONMENT in config/features.php, so this
// fixture can ask the one question no environment variable can: does a map move
// between `--env=dev` and `--env=prod`? (Lava Notes, R3-B11.)
//
// In a Lava\GatedPack namespace so ModuleRef's class shape checks pass exactly
// as they would for a composer-installed pack; require_once'd by the fixture's
// app/Modules.php, as module-app's pack is.

namespace Lava\GatedPack;

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;

final class Meter
{
    public function reading(): int
    {
        return 1;
    }
}

final class GatedPackModule implements Module
{
    public function pack(): PackInfo
    {
        return PackInfo::of('lavaphp/gated-pack', 'gated_pack');
    }

    public function register(Container $container, AppContext $ctx): void
    {
        $container->singleton(Meter::class, static fn (): Meter => new Meter());
    }
}
