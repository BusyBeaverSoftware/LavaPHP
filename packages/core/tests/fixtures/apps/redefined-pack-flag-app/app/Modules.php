<?php

declare(strict_types=1);

// Loaded with require_once: boot steps re-execute this file on every boot, and
// class definitions cannot be redeclared (see the conventions).
require_once __DIR__ . '/pack/RedefinedPackModule.php';

use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\RedefinedPack\RedefinedPackModule::class, package: 'lavaphp/redefined-pack', feature: 'redefined_pack'),
];
