<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Modules\ModuleRef;

/**
 * A module is listed in app/Modules.php and its feature is enabled, but the
 * package is not installed (the module class doesn't exist). The app refuses
 * to start, and the fix is the exact install command.
 */
final class MissingPack extends LavaProblem
{
    public function code(): string
    {
        return 'missing_pack';
    }

    public static function of(ModuleRef $ref): self
    {
        return new self(
            "Feature '{$ref->feature}' is enabled but package {$ref->package} is not installed"
            . " (class {$ref->moduleClass} not found).",
            "Run: composer require {$ref->package} — or set '{$ref->feature}' => Flag::off()"
            . ' in config/features.php and remove the module from app/Modules.php.',
            [
                'feature' => $ref->feature,
                'package' => $ref->package,
                'module_class' => $ref->moduleClass,
                'referenced_from' => (string) $ref->declaredAt,
            ],
            $ref->declaredAt,
        );
    }
}