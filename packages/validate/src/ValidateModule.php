<?php

declare(strict_types=1);

namespace Lava\Validate;

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;

/**
 * lava/validate's entry point.
 *
 * **`register()` is empty, and that is the design rather than an omission.**
 * This pack has nothing to wire: a {@see \Lava\Validate\Validation\Validator}
 * is built from a field map that only the app knows, so the container cannot
 * hold one — a singleton validator would have to be for a specific set of
 * fields, and choosing which fields belong together is exactly the decision the
 * app makes at its handler. The pack's whole API is static and dependency-free,
 * so there is no service to resolve, no config to read, and no env var to
 * declare.
 *
 * The module exists anyway, because `Modules.php` names the module that owns a
 * feature, and `lava map` reports features from that declaration. A pack with
 * no services still has to be *declarable*, or enabling it would be a
 * `MissingPack` and `--feature validate` would have nothing to gate.
 */
final class ValidateModule implements Module
{
    public function pack(): PackInfo
    {
        return PackInfo::of('lava/validate', 'validate');
    }

    public function register(Container $container, AppContext $ctx): void
    {
        // Deliberately empty — see the class docblock.
    }
}
