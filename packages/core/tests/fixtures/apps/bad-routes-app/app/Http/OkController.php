<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OkController
{
    public function ok(ServerRequestInterface $request): ResponseInterface
    {
        return Responses::json(['ok' => true]);
    }
}