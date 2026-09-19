<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\BootFailure;
use Lava\Core\Boot\RuntimeFacts;
use Lava\Core\Console\AppBoot;
use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * `lava about` — the runtime facts an agent needs before it can debug anything
 * else: PHP version, SAPI, loaded extensions, PDO drivers, which packs this app
 * can see and whether each is switched on.
 *
 * This is a plain Command, not an AppCommand, and that is the whole point: it
 * must still print the runtime facts when the app FAILS to boot. "Why won't
 * this app start" is answered better by `lava about` than by any other command,
 * and an AppCommand would have nothing to say.
 *
 * The facts themselves are {@see RuntimeFacts}, the service app code takes, so
 * a site-health page and this command cannot report different things.
 *
 * @phpstan-import-type PackFacts from RuntimeFacts
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

    /**
     * The runtime facts are the one thing knowable when nothing else is — that
     * is why `about` is a plain Command — so the seed is the real `php` block
     * rather than a typed empty. `app: null` and `packs: []` are exactly what
     * the failed-boot branch emits, and they are also the honest answer for an
     * invocation the kernel rejects before the boot: nothing was inspected, so
     * nothing is claimed about the app.
     *
     * @return array<string, mixed>
     */
    public function emptyPayload(Args $args): array
    {
        return [
            'php' => RuntimeFacts::php(),
            'framework_version' => RuntimeFacts::frameworkVersion(),
            'app' => null,
            'packs' => [],
        ];
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        $io->data('php', RuntimeFacts::php());
        // Before the boot branch, so a failed boot reports it too: "which
        // version of the framework is this" is the first thing a bug report
        // needs, and it is knowable whether or not the app starts.
        $io->data('framework_version', RuntimeFacts::frameworkVersion());
        $io->text(self::renderPhp());

        $boot = AppBoot::boot($appDir, $args->value('env'));

        if ($boot instanceof BootFailure) {
            // The app's own facts are unknowable, but the keys stay stable so
            // a `--json` consumer can tell "failed to boot" from "no packs".
            $io->data('app', null);
            $io->data('packs', []);
            return $io->emit($this->name(), $boot->problems);
        }

        $facts = $boot->container->get(RuntimeFacts::class);
        $facts = $facts instanceof RuntimeFacts
            ? $facts
            : RuntimeFacts::of($boot->appDir, $boot->env, $boot->moduleRefs, $boot->packs, $boot->features);

        // With the app, so each pack that implements ProvidesFacts is asked for
        // its own facts now — pending migrations, say. Nothing was asked at boot.
        $packs = $facts->packs($boot);
        $io->data('app', ['dir' => $facts->appDir, 'env' => $facts->env]);
        $io->data('packs', $packs);
        $io->text(sprintf("app: %s (env %s)\npacks:\n", $facts->appDir, $facts->env));
        $io->text(self::packsTable($packs));

        return $io->emit($this->name(), $boot->problems);
    }

    /** @param list<PackFacts> $packs */
    private static function packsTable(array $packs): string
    {
        return (new Table(
            ['package', 'version', 'feature', 'state', 'installed', 'config files', 'env vars', 'facts'],
            array_map(self::packRow(...), $packs),
        ))->render();
    }

    /**
     * One pack as one table row.
     *
     * The row is built here, not in an inline closure, because a closure
     * parameter declared `array` erases the shape its caller knew — the four
     * cells would come back as `mixed` and the table would have to cast them
     * back to text. A named method carries the shape in its own signature, so
     * the mapping from data to cells is checked instead of asserted.
     *
     * @param PackFacts $pack
     * @return list<string>
     */
    private static function packRow(array $pack): array
    {
        return [
            $pack['package'],
            $pack['version'] ?? '-',
            $pack['feature'],
            $pack['state'],
            $pack['installed'] ? 'yes' : 'no',
            implode(' ', $pack['config_files']),
            implode(' ', $pack['env_vars']),
            self::factsCell($pack['facts']),
        ];
    }

    /**
     * A pack's own facts as one cell, `key=value` per fact.
     *
     * The keys are the pack's, so the values are `mixed` and the rendering has
     * to be total: a bool reads as true/false rather than PHP's 1 and empty
     * string, and anything that is not a scalar goes through `json_encode` so a
     * list of names is still legible in a table.
     *
     * @param array<string, mixed> $facts
     */
    private static function factsCell(array $facts): string
    {
        $cells = [];
        foreach ($facts as $key => $value) {
            $cells[] = $key . '=' . match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => json_encode($value) ?: '?',
            };
        }

        return implode(' ', $cells);
    }

    private static function renderPhp(): string
    {
        $facts = RuntimeFacts::php();
        $drivers = $facts['pdo_drivers'] === [] ? '(none)' : implode(' ', $facts['pdo_drivers']);
        $extensions = implode(' ', $facts['extensions']);

        return "php {$facts['version']} ({$facts['sapi']}, {$facts['os']})\n"
            . 'lavaphp/core ' . (RuntimeFacts::frameworkVersion() ?? '(version unknown)') . "\n"
            . "pdo drivers: {$drivers}\n"
            . "extensions: {$extensions}\n";
    }
}
