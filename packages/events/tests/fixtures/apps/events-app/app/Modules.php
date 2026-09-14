<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\Events\EventsModule::class, package: 'lavaphp/events', feature: 'events'),
];
