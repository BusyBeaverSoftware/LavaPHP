<?php

declare(strict_types=1);

/*
 * apps/demo's own install, and nothing else.
 *
 * Unlike a package's bootstrap, there is no fallback to the monorepo root's
 * autoloader: that one maps only the packs' test namespaces, so `App\` would be
 * unfindable and every test would fail for a reason that has nothing to do with
 * the test. A missing install should say it is missing.
 */
$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "apps/demo has no vendor/ — run: cd apps/demo && composer install\n");
    exit(1);
}

require $autoload;
