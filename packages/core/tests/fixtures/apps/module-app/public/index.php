<?php

declare(strict_types=1);

// The whole HTTP entry point, fully explicit: autoload → static-file guard →
// boot → dispatch. Nothing hidden, nothing magic.

require dirname(__DIR__, 7) . '/vendor/autoload.php';

// Fixture apps have no composer.json, so nothing maps their `App\` classes to
// their own app/ directory — a real app's autoloader does that. The CLI
// harness supplies the same mapping through auto_prepend_file, but `php -S`
// does NOT run that on PHP 8.3 (it does from 8.4), so the HTTP entry point
// registers it here too. The file is idempotent, so wherever the prepend did
// run this costs one function call and nothing else.
require dirname(__DIR__, 7) . '/packages/core/tests/Support/fixture-autoload.php';

// Under `php -S`, serve real files from public/ directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== false && is_file($file)) {
        return false;
    }
}

$app = \Lava\Core\Boot\Kernel::boot(dirname(__DIR__));
$request = \Lava\Core\Http\RequestFactory::fromGlobals();

if ($app instanceof \Lava\Core\Boot\BootFailure) {
    \Lava\Core\Http\Emitter::emit($app->toResponse($request));
    return;
}

\Lava\Core\Http\Emitter::emit($app->handle($request));