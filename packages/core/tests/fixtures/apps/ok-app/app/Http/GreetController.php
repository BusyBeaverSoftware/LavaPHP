<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Lava\Core\Routing\RouteArgs;
use Psr\Http\Message\ResponseInterface;

final class GreetController
{
    public function greet(RouteArgs $args, \App\Greeter $greeter): ResponseInterface
    {
        return Responses::text($greeter->greet($args->str('name')));
    }
}