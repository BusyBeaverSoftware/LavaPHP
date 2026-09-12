<?php

declare(strict_types=1);

/**
 * Where this app's database lives.
 *
 * The default DSN is an absolute path into the app's own `var/` directory,
 * built from `__DIR__` rather than written out: a relative path would resolve
 * against the working directory (so `lava serve` from the repo root and from
 * `apps/demo` would reach two different databases), and a checked-in absolute
 * path would be wrong on every machine but the one that wrote it. `var/` is
 * gitignored, so the database file never lands in the repository.
 *
 * That default is what makes `composer install && lava db:migrate && lava serve`
 * work with no setup. Anything real overrides it — the environment wins over
 * this file, which is why a deployment never edits it:
 *
 *     DATABASE_DSN=mysql:host=127.0.0.1;dbname=tasks
 *
 * The three keys are the whole contract with `lavaphp/db`; `lava env` lists the
 * variables that shadow them. An empty string counts as unset, not as "connect
 * to nothing" — so blanking `dsn` here gets `db_not_configured` naming
 * `DATABASE_DSN`, rather than a driver error about the scheme `''`.
 */
return [
    'dsn' => 'sqlite:' . dirname(__DIR__) . '/var/demo.sqlite',
    'user' => '',
    'password' => '',
];
