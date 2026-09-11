<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Modules\ModuleRef;

/**
 * The module's PackInfo disagrees with its app/Modules.php entry. Both
 * sources declare package+feature deliberately (so reports stay exact even
 * when a pack cannot load); when the pack CAN load, the two must agree.
 */
final class ModuleMismatch extends LavaProblem
{
    public function code(): string
    {
        return 'module_mismatch';
    }

    public static function of(ModuleRef $ref, string $packPackage, string $packFeature): self
    {
        $diffs = [];
        if ($packPackage !== $ref->package) {
            $diffs[] = "package: app/Modules.php says '{$ref->package}', the module says '{$packPackage}'";
        }
        if ($packFeature !== $ref->feature) {
            $diffs[] = "feature: app/Modules.php says '{$ref->feature}', the module says '{$packFeature}'";
        }
        return new self(
            'Module ' . $ref->moduleClass . ' disagrees with its app/Modules.php entry: ' . implode('; ', $diffs) . '.',
            'Make them agree — update the entry at ' . $ref->declaredAt . ', or the pack() manifest, so both say the same package and feature.',
            [
                'module_class' => $ref->moduleClass,
                'entry' => ['package' => $ref->package, 'feature' => $ref->feature],
                'pack' => ['package' => $packPackage, 'feature' => $packFeature],
            ],
            $ref->declaredAt,
        );
    }
}