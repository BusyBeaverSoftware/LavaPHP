<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** How badly a problem hurts: fatals abort boot (exit 1), warns surface in `lava check`. */
enum Severity: string
{
    case Fatal = 'fatal';
    case Warn = 'warn';
}