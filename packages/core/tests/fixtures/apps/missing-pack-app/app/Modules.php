<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

return [
    // lava/db has no DbModule in this checkout: missing_pack, with the exact install command as the fix.
    ModuleRef::of(\Lava\Db\DbModule::class, package: 'lava/db', feature: 'db'),
    // An audience flag gating a boot-lifetime resource: invalid_gating.
    ModuleRef::of(\Lava\View\ViewModule::class, package: 'lava/view', feature: 'views'),
];