<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

function health(ServerRequestInterface $request): ResponseInterface
{
    return Responses::json(['status' => 'ok']);
}