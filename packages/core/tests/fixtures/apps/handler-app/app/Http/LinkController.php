<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Lava\Core\Routing\RouteArgs;
use Lava\Core\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;

final class LinkController
{
    public function show(RouteArgs $args, UrlGenerator $url): ResponseInterface
    {
        return Responses::json(['self' => $url->url('links.show', ['id' => $args->int('id')])]);
    }
}
