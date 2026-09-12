<?php

declare(strict_types=1);

/*
 * This app's own install, and nothing else.
 *
 * There is no fallback to the framework checkout's autoloader: that one maps
 * only the packs' test namespaces, so `App\` would be unfindable and every test
 * would fail for a reason that has nothing to do with the test. A missing
 * install should say it is missing.
 */
$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "No vendor/ — run: composer install\n");
    exit(1);
}

require $autoload;
