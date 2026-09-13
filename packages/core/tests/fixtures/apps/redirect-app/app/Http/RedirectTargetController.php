<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Lava\Core\Routing\RouteArgs;
use Psr\Http\Message\ResponseInterface;

final class RedirectTargetController
{
    public function show(RouteArgs $args): ResponseInterface
    {
        return Responses::text('post ' . $args->str('slug'));
    }

    public function home(): ResponseInterface
    {
        return Responses::text('home');
    }
}
