<?php

declare(strict_types=1);

// A fixture pack that contributes COMMANDS as well as services — the opt-in
// capability {@see \Lava\Core\Modules\ProvidesCommands} exists for. A pack
// that only serves HTTP implements ProvidesRoutes and nothing here changes.

require_once __DIR__ . '/pack/CommandsPackModule.php';

use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\DemoPack\CommandsPackModule::class, package: 'lava/demo-pack', feature: 'demo_pack'),
];
