<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

/**
 * The packs this app loads. Each is gated by a flag the PACK defines — core
 * registers `Feature::define('db', Flag::on())` from this line, and writing that
 * define into config/features.php is a boot problem rather than a duplicate.
 *
 * A pack whose flag is off is ABSENT: its routes 404 and its commands do not
 * exist. A pack that is enabled but not installed is a boot problem naming the
 * exact `composer require` to run — which is the whole reason the `package:`
 * argument is spelled out here as well as in the pack's own manifest.
 *
 * The order does not matter; packs depend on core and never on each other.
 */
return [
    ModuleRef::of(\Lava\Db\DbModule::class, package: 'lava/db', feature: 'db'),
    ModuleRef::of(\Lava\Validate\ValidateModule::class, package: 'lava/validate', feature: 'validate'),
    ModuleRef::of(\Lava\View\ViewModule::class, package: 'lava/view', feature: 'views'),
];
