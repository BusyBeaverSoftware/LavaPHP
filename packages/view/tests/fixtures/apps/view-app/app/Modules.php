<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

return [
    // Enabled unconditionally, and the pack does have a config file — so this
    // fixture exercises both halves of the manifest: `config/view.php` is read
    // by LoadPackConfig at boot, and the gate flag `views` is defined by the
    // pack from this very line.
    ModuleRef::of(\Lava\View\ViewModule::class, package: 'lavaphp/view', feature: 'views'),
];
