<?php

declare(strict_types=1);

/**
 * lava/view's settings.
 *
 * Both values here are the pack's defaults, so this file could be deleted
 * without changing anything. It is here because the demo is the canonical app,
 * and "how do I point the pack at a different template directory?" should have
 * an answer you can read rather than one you have to infer from the source.
 *
 * A relative path is resolved against the APP directory, never the working
 * directory — the same rule `config/database.php` follows, and for the same
 * reason: `lava serve` from the repo root and a request under FPM have
 * different working directories, and a template path that depends on which one
 * is running is a path that works in development and 404s in production.
 *
 * `cache` is where compiled templates go. It only matters when `view.debug` is
 * off — which is the default in production, and which the pack turns the cache
 * off for in development whatever this says, so that editing a template always
 * changes the page. An empty string compiles on every render, which is the
 * right answer for a read-only filesystem.
 */
return [
    'path' => 'views',
    'cache' => 'var/views',
];
