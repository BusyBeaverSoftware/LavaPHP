<?php

declare(strict_types=1);

/**
 * Every file in config/ is read as `<filename>.<key>`, so `name` here is
 * `app.name` in code. Each value carries its provenance — which file set it,
 * and whether the environment overrode it — which is what `lava config` reports.
 */
return [
    'name' => 'LavaPHP demo',
    'base_url' => 'http://localhost:8080',
];
