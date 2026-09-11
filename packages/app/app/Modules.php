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
 *     ModuleRef::of(Lava\Db\DbModule::class, package: 'lava/db', feature: 'db')
 *
 * and requires the matching `Feature::define('db', …)` in `config/features.php`
 * plus `composer require lava/db`.
 */
return [];
