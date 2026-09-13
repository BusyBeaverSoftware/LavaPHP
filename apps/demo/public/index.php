<?php

declare(strict_types=1);

// The whole HTTP entry point, fully explicit: autoload → static-file guard →
// boot → dispatch. Nothing hidden, nothing magic.
//
// Every other file in this app is optional; this one is not. `lava serve` runs
// it, and `lava check` reports `missing_entry_point` when it is absent.

require dirname(__DIR__) . '/vendor/autoload.php';

// Under `php -S`, serve real files from public/ directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = __DIR__ . (is_string($path) ? $path : '');
    if (is_file($file)) {
        return false;
    }
}

$app = \Lava\Core\Boot\Kernel::boot(dirname(__DIR__));
$request = \Lava\Core\Http\RequestFactory::fromGlobals();

// A boot failure is a Problem report, rendered in the same media a runtime
// error would be — so a broken app explains itself in the browser too.
// Production withholds the details from the client, and there is no app logger
// before boot, so they go to the server's error log instead.
if ($app instanceof \Lava\Core\Boot\BootFailure) {
    if ($app->env === 'prod') {
        error_log($app->text());
    }
    \Lava\Core\Http\Emitter::emit($app->toResponse($request));
    return;
}

\Lava\Core\Http\Emitter::emit($app->handle($request));
