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
        return ['php' => RuntimeFacts::php(), 'app' => null, 'packs' => []];
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        $io->data('php', RuntimeFacts::php());
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

        $packs = $facts->packs();
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
            ['package', 'feature', 'state', 'installed', 'config files', 'env vars'],
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
            $pack['feature'],
            $pack['state'],
            $pack['installed'] ? 'yes' : 'no',
            implode(' ', $pack['config_files']),
            implode(' ', $pack['env_vars']),
        ];
    }

    private static function renderPhp(): string
    {
        $facts = RuntimeFacts::php();
        $drivers = $facts['pdo_drivers'] === [] ? '(none)' : implode(' ', $facts['pdo_drivers']);
        $extensions = implode(' ', $facts['extensions']);

        return "php {$facts['version']} ({$facts['sapi']}, {$facts['os']})\n"
            . "pdo drivers: {$drivers}\n"
            . "extensions: {$extensions}\n";
    }
}
