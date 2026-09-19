<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Composer\InstalledVersions;
use Lava\Core\Features\Features;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Modules\ProvidesFacts;
use Lava\Core\Problem\LavaProblem;

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
 * @phpstan-type PackFacts array{package: string, version: string|null, feature: string, module_class: string, state: string, installed: bool, config_files: list<string>, env_vars: list<string>, declared_at: string, facts: array<string, mixed>}
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
                // What Composer installed, which is the first thing anyone asks
                // when a pack misbehaves. Null rather than absent when Composer
                // cannot say (an install without its generated files), because
                // "unknown" is a fact and a missing key would hide it.
                'version' => self::packageVersion($ref->package),
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
                // Filled in by packs(), when a caller asks with the booted app
                // in hand — never here. This runs inside boot, where a
                // constructor does no I/O, and a pack's facts often need it.
                'facts' => [],
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

    /**
     * The framework's own installed version, or null when Composer cannot say.
     *
     * Static, and beside {@see php()}, because it is knowable when nothing else
     * is: `lava about` reports it for an app that did not boot, and "which
     * version of the framework is this" is the first question a bug report has
     * to answer.
     */
    public static function frameworkVersion(): ?string
    {
        return self::packageVersion('lavaphp/core');
    }

    /**
     * Every pack in app/Modules.php order.
     *
     * Given the booted app, each enabled module that implements
     * {@see ProvidesFacts} is asked for its own facts and they are merged into
     * that pack's `facts` — here rather than at construction, because this is
     * where I/O is allowed (see the interface). Without the app, `facts` is the
     * empty map `of()` built, so a caller that has no app still gets the shape.
     *
     * @return list<PackFacts>
     */
    public function packs(?App $app = null): array
    {
        if ($app === null) {
            return $this->packs;
        }

        $packs = [];
        foreach ($this->packs as $pack) {
            $module = $app->modules[$pack['module_class']] ?? null;
            if ($module instanceof ProvidesFacts) {
                $pack['facts'] = self::factsOf($module, $app);
            }
            $packs[] = $pack;
        }

        return $packs;
    }

    /**
     * One pack's facts, or why they are missing.
     *
     * Nothing a pack does here may take the report down: `about` is the command
     * someone runs when the app is already broken, so a pack that throws while
     * being asked is recorded as an `error` fact and the other packs still
     * report. A LavaProblem's message comes too — it is written to be shown, and
     * the pack has already scrubbed what must not appear in it. Any other
     * throwable contributes its class alone: a raw driver message can carry the
     * DSN it failed to open, and `about` is not the place to print one.
     *
     * @return array<string, mixed>
     */
    private static function factsOf(ProvidesFacts $module, App $app): array
    {
        try {
            return $module->facts($app);
        } catch (LavaProblem $problem) {
            return ['error' => $problem->code(), 'message' => $problem->getMessage()];
        } catch (\Throwable $throwable) {
            return ['error' => get_debug_type($throwable)];
        }
    }

    /**
     * What Composer says it installed, or null.
     *
     * `InstalledVersions` is generated into the autoloader, so it answers for a
     * path-repository install as well as a Packagist one — but an app assembled
     * without Composer has no such class, and a package Composer does not know
     * makes `getPrettyVersion()` throw. Both are "unknown", not a failure: this
     * is a diagnostic, and it must not be the reason a report cannot render.
     */
    private static function packageVersion(string $package): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::isInstalled($package)
                ? InstalledVersions::getPrettyVersion($package)
                : null;
        } catch (\Throwable) {
            return null;
        }
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
