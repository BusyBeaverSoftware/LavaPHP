<?php

declare(strict_types=1);

// A fixture pack whose command collides with a core one. Two sources collide
// here on purpose — the module takes `routes`, the app takes `services` — so a
// test can prove that a failed registration reports a problem AND leaves the
// next source able to register: one pack's mistake must not silently drop the
// app's commands.

require_once __DIR__ . '/pack/DupPackModule.php';

use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\DemoPack\DupPackModule::class, package: 'lavaphp/demo-pack', feature: 'demo_pack'),
];
