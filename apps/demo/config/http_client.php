<?php

declare(strict_types=1);

/**
 * lavaphp/http-client's settings.
 *
 * Every value is read at boot, so a bad one — a `timeout` of `0`, a negative
 * `retries` — is a boot problem naming this key and this file, rather than a
 * surprise on whichever request happens to touch the client first.
 *
 * Every value here is deliberately NOT the pack's default. That is what makes
 * `lava config` show this file as the provenance and lets the demo's own test
 * assert the values it read, so a reader can tell the file was actually loaded
 * rather than that the defaults happen to look right.
 *
 * `retries` is a ceiling, not a promise. It applies to `GET`, `HEAD`, `PUT`,
 * `DELETE`, `OPTIONS` and `TRACE` and to nothing else, whatever it is set to: a
 * POST sent twice is two creates, so the pack refuses to retry one however this
 * file is written. `backoff_ms` is the pause between attempts, and it is short
 * here because the demo's upstream is on the same machine — a retry against
 * localhost is a second chance, not a wait.
 *
 * There is no environment variable behind these. An app that wants one to win
 * reads it in this file, the way `config/app.php` reads `UPSTREAM_URL`, so the
 * precedence stays visible in one place.
 */
return [
    'timeout' => 5,
    'connect_timeout' => 2,
    'retries' => 2,
    'backoff_ms' => 50,
    'user_agent' => 'lava-demo/1.0',
];
