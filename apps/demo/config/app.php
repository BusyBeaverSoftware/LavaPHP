<?php

declare(strict_types=1);

use Lava\Core\Config\ProcessEnv;

/**
 * Every file in config/ is read as `<filename>.<key>`, so `name` here is
 * `app.name` in code. Each value carries its provenance — which file set it,
 * and whether the environment overrode it — which is what `lava config` reports.
 */

// `UPSTREAM_URL` wins over the value below, and it is read HERE rather than in
// a module so the precedence is visible in one file instead of split between a
// config reader and a service factory. `ProcessEnv::real()` is core's own
// accessor — the one boot and the CLI both ask, so a config file cannot end up
// disagreeing with the app it configures. An empty string counts as unset, the
// same rule `config/database.php` documents: `UPSTREAM_URL=` is how a deploy
// template spells "leave this blank", and treating it as a URL would produce
// `bad_request_url` about a scheme nobody typed.
$upstream = ProcessEnv::real('UPSTREAM_URL');

return [
    'name' => 'LavaPHP demo',
    'base_url' => 'http://localhost:8080',

    // What `GET /upstream/health` fetches. The default is this app's own
    // `/health`, so the demo exercises `lavaphp/http-client` against something
    // that is genuinely running — `lava serve` in one terminal is the whole
    // setup — instead of against the internet, which would make the demo's own
    // test suite fail offline and flake online.
    'upstream' => $upstream === null || $upstream === '' ? 'http://127.0.0.1:8080' : $upstream,
];
