<?php

declare(strict_types=1);

return [
    'env' => 'dev',
    // 'api_key' matches the secret-name heuristic; 'base_url' must not.
    'api_key' => 'sk_live_this_is_a_fixture_not_a_real_key',
    'base_url' => 'http://localhost:8080',
    // A credential nested inside a harmless-looking key: 'connections' matches
    // no heuristic, and the secret is a leaf three levels down.
    'connections' => [
        'primary' => ['host' => 'db.internal', 'password' => 'nested-fixture-password'],
    ],
];
