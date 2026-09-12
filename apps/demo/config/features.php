<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

/**
 * The flags this APP defines. A pack's gate flag is not here — it is defined by
 * the pack, from its `app/Modules.php` entry, and defining it here is a boot
 * problem (`Flag 'db' is the gate for pack lavaphp/db and is defined by the pack
 * itself`). Overriding one is a `set` entry; defining one is not.
 *
 * `define` is the code default — the bottom of the resolution order. `set` is a
 * deployment override, and both are beaten by the real environment and by
 * `config/.env`. That order is what makes a flag turnable without a deploy:
 *
 *     LAVA_FEATURE_TASKS_CSV_EXPORT=off      # /tasks/export is a real 404 again
 *
 * `lava features resolve tasks_csv_export` prints which layer decided, which is
 * the question an agent asks when the app does not behave the way the file says.
 */
return [
    'define' => [
        Feature::define(
            'tasks_csv_export',
            Flag::off(),
            description: 'The GET /tasks/export route',
        ),
    ],

    'set' => [
        // The code default is off, so the route is absent from a fresh clone
        // and this line is what turns it on. Set it back to Flag::off() and
        // /tasks/export becomes a real 404 — no code change, no deploy, and
        // `lava routes` stops listing the route.
        'tasks_csv_export' => Flag::on(),
    ],
];
