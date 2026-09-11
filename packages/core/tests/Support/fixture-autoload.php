<?php

declare(strict_types=1);

/**
 * Stands in for what a real app's composer.json does: maps App\ to the app's
 * own app/ directory. Fixture apps have no composer.json (they are fixtures,
 * not installs), so the CLI test harness prepends this file into the child
 * process — without it, every App\ class is unfindable and a perfectly good
 * fixture boots with bad_handler / bad_middleware problems.
 *
 * The app dir arrives through LAVA_FIXTURE_APP_DIR because auto_prepend_file
 * cannot take an argument. In-process tests do the same thing by hand — see
 * {@see \Lava\Core\Testing\TestApp::autoloadFixture()}.
 */

if (!($GLOBALS['lava_fixture_autoloader'] ?? false)) {
    $GLOBALS['lava_fixture_autoloader'] = true;
    spl_autoload_register(static function (string $class): void {
        $appDir = getenv('LAVA_FIXTURE_APP_DIR');
        if ($appDir === false || !str_starts_with($class, 'App\\')) {
            return;
        }
        $file = rtrim($appDir, '/') . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
