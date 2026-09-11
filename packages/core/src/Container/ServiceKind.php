<?php

declare(strict_types=1);

namespace Lava\Core\Container;

/** What kind of registration an id has. */
enum ServiceKind: string
{
    case Singleton = 'singleton';
    case Factory = 'factory';
    case Value = 'value';
    case Alias = 'alias';
}