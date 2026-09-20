<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

/** One step of the boot pipeline. Steps accumulate problems on the shared report; they never fail fast. */
/**
 * One step of the boot sequence.
 *
 * @internal the kernel fixes the order and the steps; an app adds behaviour through a module, not a step
 */
interface BootStep
{
    public function run(BootCtx $ctx): void;
}