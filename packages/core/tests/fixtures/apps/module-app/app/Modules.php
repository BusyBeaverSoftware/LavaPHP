<?php

declare(strict_types=1);

// The pack's classes load once per process: the boot steps re-execute this
// entry file on every boot (tests boot fixture apps repeatedly), and class
// definitions cannot be redeclared — the same rule real apps follow for
// function handler files at the top of app/Routes.php.
require_once __DIR__ . '/pack/DemoPackModule.php';

use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\DemoPack\DemoPackModule::class, package: 'lava/demo-pack', feature: 'demo_pack'),
];