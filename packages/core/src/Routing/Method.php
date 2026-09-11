<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

/** The HTTP methods routes can declare. Every route states its methods explicitly. */
enum Method: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Options = 'OPTIONS';
}