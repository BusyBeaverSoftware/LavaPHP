<?php

declare(strict_types=1);

// The pack's own config file, and it is here for one reason: to make
// `ViewModule::pack()`'s `configFiles: ['view']` a claim the fixture can prove.
// `LoadPackConfig` reads this file at boot and folds it in as `view.*` keys,
// which is what turns a manifest entry into a config surface an app can
// actually set, with provenance, in `lava config`.
//
// The value is the default, deliberately. A fixture that changed it would be
// testing the config file; this one is testing the wiring that reads it, and
// the HTTP tests assert the resulting directory either way.
return [
    'path' => 'views',
];
