<?php

declare(strict_types=1);

// The pack's class loads once per process: the boot steps re-execute this entry
// file on every boot, and class definitions cannot be redeclared.
require_once __DIR__ . '/pack/GatedPackModule.php';

use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\GatedPack\GatedPackModule::class, package: 'lavaphp/gated-pack', feature: 'gated_pack'),
];
