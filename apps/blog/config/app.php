<?php

declare(strict_types=1);

/**
 * Every file in config/ is read as `<filename>.<key>`, so `name` here is
 * `app.name` in code.
 *
 * `env` is the one key with a reserved name: boot resolves the environment name
 * as `LAVA_ENV` (real environment or `config/.env`) → this key → 'dev', which
 * is why the value here is an override rather than the source of truth.
 */
return [
    'env' => 'dev',
    'base_url' => 'http://localhost:8080',

    'name' => 'Lava Blog',

    // The session cookie's name and lifetime. The signing secret is NOT here:
    // a key in a committed file is a key everyone shares, so it travels as the
    // required `SESSION_SECRET` environment variable instead — declared in
    // app/Services.php so `lava env` can report on it.
    'session_cookie' => 'lava_blog_session',
    'session_ttl' => 1209600, // 14 days, in seconds
];
