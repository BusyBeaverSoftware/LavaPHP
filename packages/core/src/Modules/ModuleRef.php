<?php

declare(strict_types=1);

namespace Lava\Core\Modules;

use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\SourceLocation;

/**
 * One entry of app/Modules.php: which module class to load, from which
 * composer package, gated by which feature. The package+feature pair
 * duplicates what the module's PackInfo also declares — deliberately, so
 * the MissingPack report can name the exact `composer require` command
 * even when the pack's code cannot load at all. Boot cross-checks both
 * sources; a mismatch is fatal.
 */
final readonly class ModuleRef
{
    /** @param class-string $moduleClass */
    private function __construct(
        public string $moduleClass,
        public string $package,
        public string $feature,
        public SourceLocation $declaredAt,
    ) {
    }

    /** @param class-string $moduleClass */
    public static function of(string $moduleClass, string $package, string $feature): self
    {
        $problems = [];
        if (!preg_match('/^Lava\\\\[A-Za-z0-9]+\\\\[A-Za-z0-9]+Module$/', $moduleClass)) {
            $problems[] = "module class '{$moduleClass}' should be a Pack module class like Lava\\Db\\DbModule";
        }
        if (!preg_match('/^lava\/[a-z0-9-]+$/', $package)) {
            $problems[] = "package '{$package}' should look like lava/<pack-name>";
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $feature)) {
            $problems[] = "feature '{$feature}' should be snake_case";
        }
        if ($problems !== []) {
            throw new InvalidConfig(
                'Invalid app/Modules.php entry: ' . implode('; ', $problems) . '.',
                'Use ModuleRef::of(\Lava\<Pack>\<Pack>Module::class, package: \'lava/<pack>\', feature: \'<snake_case>\').',
                ['module_class' => $moduleClass, 'package' => $package, 'feature' => $feature],
                self::caller(),
            );
        }
        $caller = self::caller();
        return new self($moduleClass, $package, $feature, $caller);
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'module_class' => $this->moduleClass,
            'package' => $this->package,
            'feature' => $this->feature,
            'declared_at' => (string) $this->declaredAt,
        ];
    }

    private static function caller(): SourceLocation
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $frames[1] ?? [];
        return SourceLocation::of($caller['file'] ?? 'unknown', $caller['line'] ?? 0);
    }
}