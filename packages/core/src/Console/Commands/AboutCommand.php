<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Console\AppBoot;
use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;
use Lava\Core\Modules\ModuleRef;

/**
 * `lava about` — the runtime facts an agent needs before it can debug anything
 * else: PHP version, SAPI, loaded extensions, PDO drivers, which packs this app
 * can see and whether each is switched on.
 *
 * This is a plain Command, not an AppCommand, and that is the whole point: it
 * must still print the runtime facts when the app FAILS to boot. "Why won't
 * this app start" is answered better by `lava about` than by any other command,
 * and an AppCommand would have nothing to say.
 */
final class AboutCommand extends Command
{
    public function name(): string
    {
        return 'about';
    }

    public function summary(): string
    {
        return 'Show runtime facts: PHP, extensions, packs, and any boot problems.';
    }

    public function flags(): array
    {
        return ['json', 'env'];
    }

    public function usage(): string
    {
        return 'lava about [--env=<name>] [--json]';
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        $io->data('php', self::phpFacts());
        $io->text(self::renderPhp());

        $boot = AppBoot::boot($appDir, $args->value('env'));

        if ($boot instanceof BootFailure) {
            // The app's own facts are unknowable, but the keys stay stable so
            // a `--json` consumer can tell "failed to boot" from "no packs".
            $io->data('app', null);
            $io->data('packs', []);
            return $io->emit($this->name(), $boot->problems);
        }

        $packs = self::packs($boot);
        $io->data('app', ['dir' => $boot->appDir, 'env' => $boot->env]);
        $io->data('packs', $packs);
        $io->text(sprintf("app: %s (env %s)\npacks:\n", $boot->appDir, $boot->env));
        $io->text((new Table(['package', 'feature', 'state', 'installed', 'config files', 'env vars'], array_map(
            static fn (array $pack): array => [
                $pack['package'],
                $pack['feature'],
                $pack['state'],
                $pack['installed'] ? 'yes' : 'no',
                implode(' ', $pack['config_files']),
                implode(' ', $pack['env_vars']),
            ],
            $packs,
        )))->render());

        return $io->emit($this->name(), $boot->problems);
    }

    /** @return array<string, mixed> */
    private static function phpFacts(): array
    {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'extensions' => self::extensions(),
            // Reported even when empty: "PDO is present but has no drivers" is
            // a diagnosis, and an absent key would hide it.
            'pdo_drivers' => class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [],
        ];
    }

    private static function renderPhp(): string
    {
        $facts = self::phpFacts();
        $drivers = $facts['pdo_drivers'] === [] ? '(none)' : implode(' ', $facts['pdo_drivers']);
        $extensions = implode(' ', $facts['extensions']);

        return "php {$facts['version']} ({$facts['sapi']}, {$facts['os']})\n"
            . "pdo drivers: {$drivers}\n"
            . "extensions: {$extensions}\n";
    }

    /** @return list<string> sorted, so two machines' `about` output diffs cleanly */
    private static function extensions(): array
    {
        $names = array_map(strtolower(...), get_loaded_extensions());
        sort($names);
        return $names;
    }

    /** @return list<array<string, mixed>> */
    private static function packs(App $app): array
    {
        $out = [];
        foreach ($app->moduleRefs as $ref) {
            $manifest = $app->packs[$ref->moduleClass] ?? null;
            $out[] = [
                'package' => $ref->package,
                'feature' => $ref->feature,
                'module_class' => $ref->moduleClass,
                'state' => self::gateState($app, $ref),
                // A pack can be switched on and still be absent from disk, or
                // switched off and installed; "state" and "installed" are
                // different facts and the table shows both.
                'installed' => $manifest !== null,
                'config_files' => $manifest->configFiles ?? [],
                'env_vars' => $manifest->envVars ?? [],
                'declared_at' => (string) $ref->declaredAt,
            ];
        }
        return $out;
    }

    /**
     * A module ref can name a feature nothing defined — boot reports that, but
     * `about` still has to render, so an undefined gate reads as 'unknown'
     * rather than throwing the very exception it is meant to help diagnose.
     */
    private static function gateState(App $app, ModuleRef $ref): string
    {
        if (!$app->features->definitions->has($ref->feature)) {
            return 'unknown';
        }
        return $app->features->on($ref->feature) ? 'enabled' : 'disabled';
    }
}
