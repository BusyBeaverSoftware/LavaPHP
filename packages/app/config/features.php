<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

/**
 * `define` is the code default — the bottom of the resolution order. `set` is a
 * deployment override. A value in the real environment or in `config/.env`
 * beats both, which is what makes a flag turnable without a deploy.
 *
 * `lava features resolve <flag>` shows which layer decided.
 */
return [
    'define' => [
        Feature::define('beta_greeting', Flag::rollout(50), description: 'The /beta/hello route'),
    ],

    'set' => [
        // The code default is rollout:50; this turns it fully on so the route
        // is reachable out of the box. Set it to Flag::off() and /beta/hello
        // becomes a real 404 — no code change, no deploy.
        'beta_greeting' => Flag::on(),
    ],
];
