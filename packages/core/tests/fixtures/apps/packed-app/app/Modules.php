<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

/**
 * The smallest app that loads a REAL pack.
 *
 * Every other core fixture either loads no pack or loads a stand-in written in
 * the fixture itself (`module-app`'s `Lava\DemoPack\…`), which is enough to test
 * that core reads a manifest, a config file and a command list — and not enough
 * to answer the one question that needs a real pack: whether the schemas a pack
 * claims are the schemas the repository documents. That answer is a property of
 * the repository rather than of core or of the pack, and this fixture is the
 * smallest app in which it can be asked.
 *
 * The pack is named, not installed here: `lavaphp/db` comes from the monorepo's own
 * dev requirements, and an app that names a module it has not downloaded is a
 * `missing_pack` problem with the exact `composer require` to run — which is why
 * a file like this one is safe to ship.
 *
 * No DSN is configured, and none is needed: `DbModule` builds its `Connection`
 * WITHOUT connecting, which is what lets the pack be enabled on an app whose
 * database does not exist yet. The commands are registered either way, and
 * registered commands are the whole of what `lava list` reports.
 */
return [
    ModuleRef::of(\Lava\Db\DbModule::class, package: 'lavaphp/db', feature: 'db'),
    ModuleRef::of(\Lava\Events\EventsModule::class, package: 'lavaphp/events', feature: 'events'),
];
