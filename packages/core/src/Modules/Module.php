<?php

declare(strict_types=1);

namespace Lava\Core\Modules;

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;

/**
 * A pack's entry point. Constructors must be parameterless — dependencies
 * arrive as register() arguments or explicit container lookups, never by
 * magic. register() runs once per boot, only when the pack's gate resolved
 * on; a gated-off pack is never instantiated for wiring.
 */
interface Module
{
    /** The pack's manifest: identity + the config files and env vars it reads. */
    public function pack(): PackInfo;

    /** Registers the pack's services on the container, in the pack's own order. */
    public function register(Container $container, AppContext $ctx): void;
}