<?php

declare(strict_types=1);

namespace Lava\Core\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * SAPI globals → PSR-7 ServerRequestInterface, via nyholm/psr7-server.
 * The only place superglobals are read into a request.
 */
final class RequestFactory
{
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        return (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
    }
}