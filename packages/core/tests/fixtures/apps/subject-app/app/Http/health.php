<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Named distinctly from every other fixture app's handlers: all fixtures
// share one process under PHPUnit, and function names collide across apps
// just like class names do.
function subject_health(ServerRequestInterface $request): ResponseInterface
{
    return Responses::json(['status' => 'ok']);
}