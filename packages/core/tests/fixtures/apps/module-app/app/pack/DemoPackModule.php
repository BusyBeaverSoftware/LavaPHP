<?php

declare(strict_types=1);

namespace Lava\DemoPack;

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Lava\Core\Http\Responses;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Modules\ProvidesRoutes;
use Lava\Core\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// A fixture pack: the module class, its one service, and its route handler
// in a Lava\DemoPack namespace so ModuleRef's class shape checks pass exactly
// as they would for a composer-installed pack (real packs autoload from
// vendor/; this file is require_once'd by the fixture's app/Modules.php).

final class Quota
{
    public function limit(): int
    {
        return 42;
    }
}

final class QuotaController
{
    public function show(ServerRequestInterface $request, Quota $quota): ResponseInterface
    {
        return Responses::json(['limit' => $quota->limit()]);
    }
}

final class DemoPackModule implements Module, ProvidesRoutes
{
    public function pack(): PackInfo
    {
        return PackInfo::of('lava/demo-pack', 'demo_pack', envVars: ['DEMO_API_KEY']);
    }

    public function register(Container $container, AppContext $ctx): void
    {
        $container->singleton(Quota::class, static fn (Container $c) => new Quota());
    }

    public function routes(Router $router): void
    {
        $router->get('/demo/quota', 'demo.quota')->handler([QuotaController::class, 'show']);
    }
}