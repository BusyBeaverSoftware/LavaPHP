<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Features\Features;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Modules\PackInfo;

/**
 * The runtime facts `lava about` reports, as a service app code can take: the
 * PHP the app runs on, the app itself, and every pack `app/Modules.php` names,
 * with whether it is switched on and installed.
 *
 * A site-health page needs the same facts the command prints, and computing
 * them twice is how the two drift, so `AboutCommand` reads them from here too.
 * The PHP half is static, because `about` must report it for an app that did
 * not boot; the app half is built once, at boot, from what the boot found.
 *
 * `lava check` is not here and will not be: it runs the test suite, which is
 * not something a request should do.
 *
 * @phpstan-type PhpFacts array{version: string, sapi: string, os: string, extensions: list<string>, pdo_drivers: list<string>}
 * @phpstan-type PackFacts array{package: string, feature: string, module_class: string, state: string, installed: bool, config_files: list<string>, env_vars: list<string>, declared_at: string}
 */
final readonly class RuntimeFacts
{
    /**
     * @param list<PackFacts> $packs
     */
    public function __construct(
        public string $appDir,
        public string $env,
        private array $packs,
    ) {
    }

    /**
     * @param list<ModuleRef> $moduleRefs every entry of app/Modules.php, in order
     * @param array<string, PackInfo> $manifests every pack manifest the app can see, by module class
     * @param Features $features the boot's resolver, which decides a pack's gate
     */
    public static function of(string $appDir, string $env, array $moduleRefs, array $manifests, Features $features): self
    {
        $packs = [];
        foreach ($moduleRefs as $ref) {
            $manifest = $manifests[$ref->moduleClass] ?? null;
            $packs[] = [
                'package' => $ref->package,
                'feature' => $ref->feature,
                'module_class' => $ref->moduleClass,
                'state' => self::gateState($features, $ref),
                // A pack can be switched on and still be absent from disk, or
                // switched off and installed; "state" and "installed" are
                // different facts and both are reported.
                'installed' => $manifest !== null,
                'config_files' => $manifest->configFiles ?? [],
                'env_vars' => $manifest->envVars ?? [],
                'declared_at' => (string) $ref->declaredAt,
            ];
        }

        return new self($appDir, $env, $packs);
    }

    /** @return PhpFacts */
    public static function php(): array
    {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'extensions' => self::extensions(),
            // Reported even when empty: "PDO is present but has no drivers" is
            // a diagnosis, and an absent key would hide it.
            'pdo_drivers' => self::pdoDrivers(),
        ];
    }

    /** @return list<PackFacts> in app/Modules.php order */
    public function packs(): array
    {
        return $this->packs;
    }

    /** @return list<string> sorted, so two machines' facts diff cleanly */
    private static function extensions(): array
    {
        $names = array_map(strtolower(...), get_loaded_extensions());
        sort($names);

        return $names;
    }

    /**
     * PDO's available drivers, as a list of names.
     *
     * `PDO::getAvailableDrivers()` is typed `array<int|string, mixed>` — the
     * stub cannot say more — while its contract is a list of driver names. The
     * names are collected here so the declared shape is one something actually
     * enforces.
     *
     * @return list<string>
     */
    private static function pdoDrivers(): array
    {
        if (!class_exists(\PDO::class)) {
            return [];
        }

        $drivers = [];
        foreach (\PDO::getAvailableDrivers() as $driver) {
            if (is_string($driver)) {
                $drivers[] = $driver;
            }
        }

        return $drivers;
    }

    /**
     * A module ref can name a feature nothing defined — boot reports that, but
     * the facts still have to be readable, so an undefined gate reads as
     * 'unknown' rather than throwing the very exception they help diagnose.
     */
    private static function gateState(Features $features, ModuleRef $ref): string
    {
        if (!$features->definitions->has($ref->feature)) {
            return 'unknown';
        }

        return $features->on($ref->feature) ? 'enabled' : 'disabled';
    }
}
