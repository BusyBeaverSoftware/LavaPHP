<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

/**
 * One app-owned flag. `signups_open` gates the registration route and the link
 * to it, so closing signups is a config change rather than a deploy.
 *
 * `define` is the code default — the bottom of the resolution order. `set` is a
 * deployment override, and `LAVA_FEATURE_SIGNUPS_OPEN=off` in the environment
 * beats both. `lava features resolve signups_open` names the layer that decided.
 *
 * The packs' own gates (`db`, `validate`, `views`) are NOT here: a pack defines
 * the flag that gates it, and writing one here is a boot problem rather than a
 * harmless duplicate. This file owns only app features.
 */
return [
    'define' => [
        Feature::define('signups_open', Flag::on(), description: 'Whether GET/POST /register is reachable'),
    ],

    'set' => [],
];
