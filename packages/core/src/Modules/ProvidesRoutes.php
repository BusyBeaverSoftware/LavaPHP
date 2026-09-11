<?php

declare(strict_types=1);

namespace Lava\Core\Modules;

use Lava\Core\Routing\Router;

/**
 * A module that contributes routes. routes() is called by BuildRouter after
 * app/Routes.php, so on any path overlap the app's own registration wins —
 * the app can always override a pack route by registering the same path.
 */
interface ProvidesRoutes
{
    public function routes(Router $router): void;
}