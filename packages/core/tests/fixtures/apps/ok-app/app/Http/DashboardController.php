<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DashboardController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return Responses::json(['dashboard' => 'beta']);
    }
}