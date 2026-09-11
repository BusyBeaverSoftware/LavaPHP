<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

/** One step of the boot pipeline. Steps accumulate problems on the shared report; they never fail fast. */
interface BootStep
{
    public function run(BootCtx $ctx): void;
}