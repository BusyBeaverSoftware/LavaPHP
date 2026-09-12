<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

/**
 * The packs this app loads, each gated by a feature flag. A module whose flag is
 * off is ABSENT: its routes 404 and its commands do not exist. A pack that is
 * enabled but not installed is a boot problem naming the exact
 * `composer require` to run.
 *
 * This app loads none, so there is nothing to enable. Adding one looks like:
 *
 *     ModuleRef::of(Lava\Db\DbModule::class, package: 'lavaphp/db', feature: 'db')
 *
 * and takes exactly two things: that line, and `composer require lavaphp/db`.
 *
 * The flag is NOT one of them. `db` is the pack's GATE, so the pack defines it
 * — core reads this file and registers `Feature::define('db', Flag::on())` for
 * you. Writing it yourself is a boot problem rather than a harmless duplicate:
 * "Flag 'db' is the gate for pack lavaphp/db and is defined by the pack itself."
 * The pack owns the flag the same way it owns its routes and its commands, and
 * this file owns only the decision to load it.
 *
 * To turn the pack OFF, override the flag rather than deleting this line —
 * `'set' => ['db' => Flag::off()]` in `config/features.php`, or
 * `LAVA_FEATURE_DB=off` in the environment. Deleting the line would instead
 * make the pack's services, routes and commands disappear in a way no config
 * file records, and `lava features resolve db` would have nothing to explain.
 *
 * An override is not free, and the framework does not pretend otherwise. The
 * pack's services are gone, so any app service that type-hints one is now a
 * dangling reference — `app/Services.php` registering a repository that takes
 * `Lava\Db\Connection` boots to a `service_not_registered` problem naming that
 * file and line. That is the wiring contract doing its job: with no auto-wiring
 * there is nothing to fall back to, so the failure is a boot problem with the
 * line to fix rather than a 500 on the first request that touches it.
 */
return [];
