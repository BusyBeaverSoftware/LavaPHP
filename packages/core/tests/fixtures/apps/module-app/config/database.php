<?php

declare(strict_types=1);

/**
 * The config file `Lava\DemoPack\DemoPackModule` declares, present so the
 * fixture exercises the case where the manifest and the filesystem agree.
 *
 * Core reads it because the pack declared it — `LoadPackConfig` turns
 * `configFiles: ['database']` into this path — and `lava config` shows its keys
 * like any core key. Nothing in the pack loads it itself.
 */
return [
    'dsn' => '',
];
