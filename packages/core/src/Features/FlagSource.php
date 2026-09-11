<?php

declare(strict_types=1);

namespace Lava\Core\Features;

/** Which layer of the resolution order decided a flag's value. */
enum FlagSource: string
{
    case Code = 'code';
    case Config = 'config';
    case Env = 'env';
    case Subject = 'subject';
}