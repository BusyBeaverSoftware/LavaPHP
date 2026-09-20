<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Boot\RuntimeFacts;
use Lava\Core\Console\AppBoot;
use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;
use Lava\Core\Map\ApiIndex;

/**
 * `lava api` — what the FRAMEWORK offers, as opposed to what this app declares.
 *
 * The question it answers is "is there already a method for this?", and it
 * exists because the answer was repeatedly no when it should have been yes:
 * across three outside builds, 33 of 70 "missing feature" reports named
 * capabilities that had shipped, each one paid for with a workaround — one of
 * them 690 lines of security-sensitive code. Grep can only report what it saw;
 * this reports over a closed surface ({@see \Lava\Core\Map\ApiSurface}), so
 * "nothing matches" is an answer rather than a shrug.
 *
 * A plain Command, not an AppCommand, for the same reason {@see AboutCommand}
 * is: the framework's API does not depend on the app booting, and an agent
 * asking "what can I call to fix this boot failure?" must not be told to fix
 * the boot first. It boots opportunistically all the same, because whether a
 * pack is switched ON here is an app fact worth reporting — and a pack that is
 * off is still indexed, since not knowing a capability exists is the failure
 * being fixed.
 *
 * It is deliberately separate from `lava describe`, which resolves this app's
 * routes, services, flags, env vars and commands: two questions, two
 * commands, and folding six hundred framework methods into `describe`'s
 * namespace precedence would make its answers ambiguous.
 */
final class ApiCommand extends Command
{
    /**
     * A search shows at most this many symbols.
     *
     * `total` still reports the real count and `truncated` says it was cut, so
     * a narrower query is the reader's next move rather than a page cursor —
     * which would be state to carry for a corpus of a few hundred entries.
     */
    private const LIMIT = 50;

    public function name(): string
    {
        return 'api';
    }

    public function summary(): string
    {
        return "Search the framework's own API: classes, methods and signatures.";
    }

    public function flags(): array
    {
        return ['json', 'search', 'pack', 'all', 'env'];
    }

    public function arguments(): array
    {
        return ['symbol'];
    }

    public function usage(): string
    {
        return 'lava api [<symbol>] [--search=<term>] [--pack=<name>] [--all] [--json]';
    }

    /**
     * The roster answers without an app, so it is the honest seed: an
     * invocation the kernel rejects still tells a consumer which packs are
     * installed and how large each surface is. `symbols` is empty because
     * nothing was asked for, not because nothing matched — `query` being null
     * is what says so.
     *
     * @return array<string, mixed>
     */
    public function emptyPayload(Args $args): array
    {
        $cwd = getcwd();
        $index = ApiIndex::of(ApiIndex::surfaces(), $cwd === false ? '.' : $cwd);

        return [
            'query' => null,
            'mode' => 'roster',
            'total' => count($index->symbols),
            'truncated' => false,
            'packs' => $index->packs,
            'symbols' => [],
        ];
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        $index = ApiIndex::of(ApiIndex::surfaces(), $appDir, self::gateStates($appDir, $args->value('env')));

        $query = $args->value('search') ?? $args->arg(0);
        $pack = $args->value('pack');
        $symbols = self::scoped($index->symbols, $pack);

        [$mode, $matches] = match (true) {
            $query !== null => self::resolve($symbols, $query, $args->has('search')),
            $args->bool('all') => ['all', $symbols],
            $pack !== null => ['pack', $symbols],
            default => ['roster', []],
        };

        // In roster mode nothing was asked for, so the honest total is the size
        // of what could be asked about — which is also what emptyPayload() says.
        $total = $mode === 'roster' ? count($symbols) : count($matches);
        $shown = $mode === 'search' ? array_slice($matches, 0, self::LIMIT) : $matches;

        $io->data('query', $query ?? ($pack !== null ? $pack : null));
        $io->data('mode', $mode);
        $io->data('total', $total);
        $io->data('truncated', count($shown) < $total);
        $io->data('packs', $index->packs);
        $io->data('symbols', array_values($shown));
        $io->text(self::render($index, $mode, $query, $pack, $total, array_values($shown)));

        return $io->emit($this->name());
    }

    /**
     * Which packs are switched on here, when the app can say.
     *
     * A failed boot is not an error for this command: the framework's surface
     * is the same either way, and the gate column simply reports nothing.
     *
     * @return array<string, bool>
     */
    private static function gateStates(string $appDir, ?string $env): array
    {
        $boot = AppBoot::boot($appDir, $env);
        if (!$boot instanceof App) {
            return [];
        }

        $facts = $boot->container->get(RuntimeFacts::class);
        if (!$facts instanceof RuntimeFacts) {
            return [];
        }

        $states = [];
        foreach ($facts->packs() as $pack) {
            $states[$pack['package']] = $pack['state'] === 'enabled';
        }

        return $states;
    }

    /**
     * @param array<string, array<string, mixed>> $symbols
     * @return array<string, array<string, mixed>>
     */
    private static function scoped(array $symbols, ?string $pack): array
    {
        if ($pack === null) {
            return $symbols;
        }

        return array_filter($symbols, static fn (array $symbol): bool => $symbol['pack'] === $pack);
    }

    /**
     * What the reader meant, in the order they most likely meant it.
     *
     * Method-first, because the question an agent arrives with is "is there a
     * method for X" — a class name it could already guess is the rarer case, and
     * it is still matched exactly before any search runs. `--search` skips
     * straight to the last step, for a reader who knows the word is not a name.
     *
     * @param array<string, array<string, mixed>> $symbols
     * @return array{0: string, 1: array<string, array<string, mixed>>}
     */
    private static function resolve(array $symbols, string $query, bool $forceSearch): array
    {
        if (!$forceSearch) {
            if (str_contains($query, '::')) {
                [$class, $method] = explode('::', $query, 2);
                $found = self::methodMatches($symbols, $method, $class);
                if ($found !== []) {
                    return ['method', $found];
                }
            }

            $exact = self::classMatches($symbols, $query);
            if ($exact !== []) {
                return ['class', $exact];
            }

            $byMethod = self::methodMatches($symbols, $query, null);
            if ($byMethod !== []) {
                return ['method', $byMethod];
            }
        }

        return ['search', self::search($symbols, $query)];
    }

    /**
     * Classes whose name matches exactly, fully qualified or short.
     *
     * @param array<string, array<string, mixed>> $symbols
     * @return array<string, array<string, mixed>>
     */
    private static function classMatches(array $symbols, string $query): array
    {
        $wanted = strtolower(ltrim($query, '\\'));

        return array_filter($symbols, static function (array $symbol) use ($wanted): bool {
            $name = is_string($symbol['name']) ? $symbol['name'] : '';
            $short = strrpos($name, '\\');

            return strtolower($name) === $wanted
                || ($short !== false && strtolower(substr($name, $short + 1)) === $wanted);
        });
    }

    /**
     * Symbols carrying a method of exactly this name, each cut down to the
     * matching methods — a hit is about the method, so listing its class's
     * other forty would bury it.
     *
     * @param array<string, array<string, mixed>> $symbols
     * @return array<string, array<string, mixed>>
     */
    private static function methodMatches(array $symbols, string $method, ?string $class): array
    {
        $wanted = strtolower($method);
        $onClass = $class === null ? null : strtolower(ltrim($class, '\\'));
        $found = [];

        foreach ($symbols as $name => $symbol) {
            if ($onClass !== null && !self::isNamed($name, $onClass)) {
                continue;
            }
            $methods = is_array($symbol['methods']) ? $symbol['methods'] : [];
            $hits = array_values(array_filter(
                $methods,
                static fn (mixed $m): bool => is_array($m) && is_string($m['name'] ?? null) && strtolower($m['name']) === $wanted,
            ));
            if ($hits !== []) {
                $found[$name] = ['methods' => $hits] + $symbol;
            }
        }

        return $found;
    }

    private static function isNamed(string $name, string $wanted): bool
    {
        $short = strrpos($name, '\\');

        return strtolower($name) === $wanted || ($short !== false && strtolower(substr($name, $short + 1)) === $wanted);
    }

    /**
     * Substring search over method names, class names and summaries — the
     * corpus a reader's word could plausibly be in. A class hit keeps all its
     * methods; a method hit keeps the matching ones.
     *
     * @param array<string, array<string, mixed>> $symbols
     * @return array<string, array<string, mixed>>
     */
    private static function search(array $symbols, string $query): array
    {
        $needle = strtolower($query);
        $found = [];

        foreach ($symbols as $name => $symbol) {
            $summary = is_string($symbol['summary'] ?? null) ? $symbol['summary'] : '';
            $classHit = str_contains(strtolower($name), $needle) || str_contains(strtolower($summary), $needle);

            $methods = is_array($symbol['methods']) ? $symbol['methods'] : [];
            $hits = array_values(array_filter($methods, static function (mixed $m) use ($needle): bool {
                if (!is_array($m)) {
                    return false;
                }
                $name = is_string($m['name'] ?? null) ? $m['name'] : '';
                $summary = is_string($m['summary'] ?? null) ? $m['summary'] : '';

                return str_contains(strtolower($name), $needle) || str_contains(strtolower($summary), $needle);
            }));

            if ($classHit) {
                $found[$name] = $symbol;
            } elseif ($hits !== []) {
                $found[$name] = ['methods' => $hits] + $symbol;
            }
        }

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $symbols
     */
    private static function render(ApiIndex $index, string $mode, ?string $query, ?string $pack, int $total, array $symbols): string
    {
        if ($mode === 'roster') {
            return self::roster($index);
        }

        if ($mode === 'class' && count($symbols) === 1) {
            return self::detail($symbols[0]);
        }

        $rows = [];
        foreach ($symbols as $symbol) {
            $methods = is_array($symbol['methods']) ? $symbol['methods'] : [];
            $name = is_string($symbol['name']) ? $symbol['name'] : '';
            if ($methods === []) {
                $rows[] = [self::short($name), self::text($symbol['pack']), '', self::text($symbol['summary'] ?? null)];
                continue;
            }
            foreach ($methods as $method) {
                if (!is_array($method)) {
                    continue;
                }
                $rows[] = [
                    self::short($name) . '::' . self::text($method['name'] ?? null),
                    self::text($symbol['pack']),
                    self::text($method['signature'] ?? null),
                    self::text($method['summary'] ?? $symbol['summary'] ?? null),
                ];
            }
        }

        $what = $query ?? $pack ?? 'the framework';
        $header = sprintf(
            "%d %s for \"%s\"%s\n\n",
            $total,
            $total === 1 ? 'match' : 'matches',
            $what,
            count($symbols) < $total ? sprintf(' (showing %d — narrow it, or use --json)', count($symbols)) : '',
        );

        return $header . (new Table(['symbol', 'pack', 'signature', 'what it does'], $rows))->render()
            . "\nOne class in full: lava api <ClassName>\n";
    }

    private static function roster(ApiIndex $index): string
    {
        $rows = [];
        foreach ($index->packs as $pack) {
            $rows[] = [
                $pack['pack'],
                $pack['package'],
                $pack['version'] ?? '-',
                $pack['feature'] ?? '-',
                match ($pack['enabled']) {
                    true => 'on',
                    false => 'off',
                    default => '-',
                },
                (string) $pack['types'],
                (string) $pack['methods'],
            ];
        }

        return "The framework's API, by pack. A pack that is off is still listed:\nnot knowing a capability exists is the problem this command solves.\n\n"
            . (new Table(['pack', 'package', 'version', 'feature', 'gate', 'types', 'methods'], $rows))->render()
            . "\nlava api <ClassName>      one class in full, with an example where there is one\n"
            . "lava api <methodName>     every class that has a method by that name\n"
            . "lava api --search=<term>  names and summaries\n";
    }

    /** @param array<string, mixed> $symbol */
    private static function detail(array $symbol): string
    {
        $out = self::text($symbol['name'] ?? null) . "  (" . self::text($symbol['kind'] ?? null) . ', ' . self::text($symbol['pack'] ?? null) . ")\n"
            . self::text($symbol['at'] ?? null) . "\n";

        $summary = $symbol['summary'] ?? null;
        if (is_string($summary)) {
            $out .= "\n" . $summary . "\n";
        }

        $extends = $symbol['extends'] ?? null;
        if (is_string($extends)) {
            $out .= "\nextends " . $extends . "\n";
        }
        $implements = is_array($symbol['implements'] ?? null) ? $symbol['implements'] : [];
        if ($implements !== []) {
            $out .= 'implements ' . implode(', ', array_map(self::text(...), $implements)) . "\n";
        }

        $cases = is_array($symbol['cases'] ?? null) ? $symbol['cases'] : [];
        if ($cases !== []) {
            $out .= "\ncases: " . implode(', ', array_map(self::text(...), $cases)) . "\n";
        }

        $constants = is_array($symbol['constants'] ?? null) ? $symbol['constants'] : [];
        if ($constants !== []) {
            $rows = [];
            foreach ($constants as $constant) {
                if (is_array($constant)) {
                    $rows[] = [self::text($constant['name'] ?? null), self::text($constant['value'] ?? null)];
                }
            }
            $out .= "\n" . (new Table(['constant', 'value'], $rows))->render();
        }

        $methods = is_array($symbol['methods'] ?? null) ? $symbol['methods'] : [];
        $rows = [];
        foreach ($methods as $method) {
            if (is_array($method)) {
                $rows[] = [self::text($method['signature'] ?? null), self::text($method['summary'] ?? null)];
            }
        }
        $out .= "\n" . (new Table(['method', 'what it does'], $rows))->render();

        $example = $symbol['example'] ?? null;
        if (is_string($example)) {
            $out .= "\nexample:\n\n" . $example . "\n";
        }

        return $out;
    }

    private static function short(string $class): string
    {
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
