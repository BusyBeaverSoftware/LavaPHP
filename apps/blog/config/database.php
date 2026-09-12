<?php

declare(strict_types=1);

/**
 * The DSN is a path relative to THIS directory — the app directory — and not to
 * the working directory, so `lava db:migrate` behaves the same whether it is run
 * from here, from a parent, or by a test process.
 *
 * `DATABASE_DSN` in the environment (or config/.env) overrides it, which is how
 * the test suite swaps in `sqlite::memory:`. An empty string counts as unset in
 * both places, so commenting the line out is the same as deleting it.
 */
return [
    'dsn' => 'sqlite:' . dirname(__DIR__) . '/var/blog.sqlite',
    'user' => '',
    'password' => '',
];
