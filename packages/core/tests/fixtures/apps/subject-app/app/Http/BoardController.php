<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Lava\Core\Routing\RouteArgs;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BoardController
{
    public function show(ServerRequestInterface $request, RouteArgs $args): ResponseInterface
    {
        return Responses::json(['board' => $args->routeName]);
    }
}