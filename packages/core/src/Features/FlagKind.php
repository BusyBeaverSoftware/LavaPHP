<?php

declare(strict_types=1);

namespace Lava\Core\Features;

/** What shape a flag setting has. */
enum FlagKind: string
{
    case On = 'on';
    case Off = 'off';
    case Rollout = 'rollout';
    case Users = 'users';
    case PerEnv = 'per_env';
}