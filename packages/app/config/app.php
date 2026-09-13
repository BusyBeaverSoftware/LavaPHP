<?php

declare(strict_types=1);

/**
 * Read as `app.<key>`, so `greeting` here is `app.greeting` in code. Core reads
 * this file and config/logging.php, and a pack reads the config files it
 * declares, while it is enabled; any other file in config/ is not read, so an
 * app's own settings belong here. Each value carries its provenance (which file
 * set it, and whether the environment overrode it), which is what `lava config`
 * reports.
 */
return [
    'env' => 'dev',
    'base_url' => 'http://localhost:8080',
    'greeting' => 'Hello',
];
