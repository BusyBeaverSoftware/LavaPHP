<?php

declare(strict_types=1);

/**
 * Both paths are relative to the app directory. `cache` is where compiled
 * templates go; the pack forces it off in debug (which is `env !== 'prod'`),
 * so in development a changed template is picked up on the next request.
 */
return [
    'path' => 'views',
    'cache' => 'var/views',
];
