<?php

declare(strict_types=1);

namespace Lava\Core\Modules;

use Lava\Core\Problem\ModuleMismatch;

/**
 * The boot cross-check between the two places a pack's identity is declared:
 * the app's ModuleRef (which exists even when the pack cannot load) and the
 * loaded module's PackInfo. Deliberate redundancy, checked loudly — that is
 * the design, not an accident.
 */
final class ModuleCheck
{
    /** @throws ModuleMismatch when the module's PackInfo disagrees with its app/Modules.php entry */
    public static function crossCheck(ModuleRef $ref, PackInfo $pack): void
    {
        if ($pack->package !== $ref->package || $pack->feature !== $ref->feature) {
            throw ModuleMismatch::of($ref, $pack->package, $pack->feature);
        }
    }
}