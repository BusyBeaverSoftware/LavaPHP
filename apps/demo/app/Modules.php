<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

/**
 * The packs this app loads, each gated by its own flag.
 *
 * The gate flag is defined HERE, by the pack — not in `config/features.php`.
 * A module's flag belongs to the pack, and re-defining it in the app is a boot
 * problem (`Flag 'db' is the gate for pack lavaphp/db and is defined by the pack
 * itself`). An entry below is already `Flag::on()`; what overrides it is a
 * `set` entry in `config/features.php`, the real environment, or `config/.env`:
 *
 *     LAVA_FEATURE_DB=off
 *
 * That is the whole cost of turning a pack off. Its routes 404, its `db:*`
 * commands do not exist, and `lava routes --all` still lists what it would have
 * served — so "off" reads as off, never as "the pack is broken".
 *
 * A pack that is enabled but not installed is a boot problem naming the exact
 * `composer require lavaphp/db` to run, which is why this file can name the module
 * class of a pack the app has not downloaded yet.
 */
return [
    ModuleRef::of(\Lava\Db\DbModule::class, package: 'lavaphp/db', feature: 'db'),
    ModuleRef::of(\Lava\Validate\ValidateModule::class, package: 'lavaphp/validate', feature: 'validate'),
    ModuleRef::of(\Lava\View\ViewModule::class, package: 'lavaphp/view', feature: 'views'),
    ModuleRef::of(\Lava\HttpClient\HttpClientModule::class, package: 'lavaphp/http-client', feature: 'http_client'),
    ModuleRef::of(\Lava\Events\EventsModule::class, package: 'lavaphp/events', feature: 'events'),
];
