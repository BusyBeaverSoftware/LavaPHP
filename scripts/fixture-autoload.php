<?php

declare(strict_types=1);

/**
 * TEMPORARY harness piece for scripts/gate.php (delete with it): fixture apps
 * have no composer.json, so this prepend gives a php -S child the same
 * App\ → app/ autoloader a real app gets from its composer.json (and TestApp
 * registers in-process for the PHPUnit path). The app dir arrives via
 * LAVA_FIXTURE_APP_DIR because auto_prepend_file cannot take arguments.
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