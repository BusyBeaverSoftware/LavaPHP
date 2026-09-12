<?php

declare(strict_types=1);

namespace Lava\RedefinedPack;

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;

// A fixture pack whose gate flag the app ALSO declares in config/features.php.
// CollectFlagDefinitions must catch that redefinition: a pack owns its gate
// flag, and an app redefining it would make the flag's meaning ambiguous.
// Namespace is distinct from module-app's Lava\DemoPack — fixture classes are
// process-global, and a redeclare is an uncatchable fatal for the whole suite.

final class RedefinedPackModule implements Module
{
    public function pack(): PackInfo
    {
        return PackInfo::of('lavaphp/redefined-pack', 'redefined_pack');
    }

    public function register(Container $container, AppContext $ctx): void
    {
    }
}
