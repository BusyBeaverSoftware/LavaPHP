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

        // `matched` is present on every symbol, in every mode, because a
        // consumer that has to test for a key cannot tell "not a search" from
        // "the search did not say". Only a search sets it.
        $shown = array_map(static fn (array $symbol): array => $symbol + ['matched' => null], $shown);

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
                [$class, $member] = explode('::', $query, 2);
                $found = self::methodMatches($symbols, $member, $class);
                if ($found !== []) {
                    return ['method', $found];
                }
                $property = self::propertyMatches($symbols, ltrim($member, '$'), $class);
                if ($property !== []) {
                    return ['property', $property];
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

            // A property name, last among the exact matches and before any
            // search: in a framework of `final readonly` value objects, `appDir`
            // and `routeName` are the question as often as a method name is.
            $byProperty = self::propertyMatches($symbols, ltrim($query, '$'), null);
            if ($byProperty !== []) {
                return ['property', $byProperty];
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
     * Symbols carrying a public property of exactly this name, cut down to it.
     *
     * @param array<string, array<string, mixed>> $symbols
     * @return array<string, array<string, mixed>>
     */
    private static function propertyMatches(array $symbols, string $property, ?string $class): array
    {
        $wanted = strtolower($property);
        $onClass = $class === null ? null : strtolower(ltrim($class, '\\'));
        $found = [];

        foreach ($symbols as $name => $symbol) {
            if ($onClass !== null && !self::isNamed($name, $onClass)) {
                continue;
            }
            $properties = is_array($symbol['properties'] ?? null) ? $symbol['properties'] : [];
            $hits = array_values(array_filter(
                $properties,
                static fn (mixed $p): bool => is_array($p) && is_string($p['name'] ?? null) && strtolower($p['name']) === $wanted,
            ));
            if ($hits !== []) {
                // The methods go too: a hit is about the property, and a value
                // object's other fields are the context for it, not noise.
                $found[$name] = ['properties' => $hits, 'methods' => []] + $symbol;
            }
        }

        return $found;
    }

    /**
     * Substring search over the names — class, method, property — and over the
     * summaries, which are two different answers and are labelled as such.
     *
     * A name match is the thing itself; a prose match is a sentence that happens
     * to contain the word, so "1 match for session" could be the word
     * "mid-session" in an unrelated helper's docblock. Never misleading to read,
     * and misleading to COUNT — an agent branching on `total` was the reader
     * this distinction is for (Lava Notes round 4). Each symbol therefore
     * carries `matched`, and the two groups are counted separately.
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
            $nameHit = str_contains(strtolower($name), $needle);
            $proseHit = str_contains(strtolower($summary), $needle);

            $methods = self::membersMatching($symbol['methods'] ?? null, $needle);
            $properties = self::membersMatching($symbol['properties'] ?? null, $needle);

            if ($nameHit || $proseHit) {
                // A class hit keeps the whole class: the reader asked about it,
                // not about one of its members.
                $found[$name] = ['matched' => $nameHit ? 'name' : 'prose'] + $symbol;
                continue;
            }
            if ($methods['hits'] === [] && $properties['hits'] === []) {
                continue;
            }
            $found[$name] = [
                'matched' => $methods['byName'] || $properties['byName'] ? 'name' : 'prose',
                'methods' => $methods['hits'],
                'properties' => $properties['hits'],
            ] + $symbol;
        }

        return $found;
    }

    /**
     * The members whose name or summary contains the needle, and whether any of
     * them matched by NAME.
     *
     * The member type is `array<mixed>` rather than `array<string, mixed>`
     * because it arrives through the payload as `mixed`: being an array is all
     * that is proven, and every read below states what it expects.
     *
     * @return array{hits: list<array<mixed>>, byName: bool}
     */
    private static function membersMatching(mixed $members, string $needle): array
    {
        $hits = [];
        $byName = false;

        foreach (is_array($members) ? $members : [] as $member) {
            if (!is_array($member)) {
                continue;
            }
            $name = is_string($member['name'] ?? null) ? $member['name'] : '';
            $summary = is_string($member['summary'] ?? null) ? $member['summary'] : '';
            $named = str_contains(strtolower($name), $needle);
            if (!$named && !str_contains(strtolower($summary), $needle)) {
                continue;
            }
            $byName = $byName || $named;
            $hits[] = $member;
        }

        return ['hits' => $hits, 'byName' => $byName];
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

        // Fully qualified, not shortened: a result a reader cannot `use` costs
        // them a second command, which is what happened to the round-4 builder
        // when its first test run named a class that does not exist.
        $search = $mode === 'search';
        $rows = [];
        foreach ($symbols as $symbol) {
            $name = is_string($symbol['name']) ? $symbol['name'] : '';
            $matched = $search ? [self::text($symbol['matched'] ?? null)] : [];
            $members = 0;

            foreach (is_array($symbol['methods'] ?? null) ? $symbol['methods'] : [] as $method) {
                if (!is_array($method)) {
                    continue;
                }
                $members++;
                $rows[] = array_merge([
                    $name . '::' . self::text($method['name'] ?? null),
                    self::text($symbol['pack']),
                    self::text($method['signature'] ?? null),
                    self::text($method['summary'] ?? $symbol['summary'] ?? null),
                ], $matched);
            }

            foreach (is_array($symbol['properties'] ?? null) ? $symbol['properties'] : [] as $property) {
                if (!is_array($property)) {
                    continue;
                }
                $members++;
                $rows[] = array_merge([
                    $name . '::$' . self::text($property['name'] ?? null),
                    self::text($symbol['pack']),
                    self::property($property),
                    self::text($property['summary'] ?? $symbol['summary'] ?? null),
                ], $matched);
            }

            if ($members === 0) {
                $rows[] = array_merge([$name, self::text($symbol['pack']), '', self::text($symbol['summary'] ?? null)], $matched);
            }
        }

        $what = $query ?? $pack ?? 'the framework';
        $header = sprintf(
            "%d %s for \"%s\"%s%s\n\n",
            $total,
            $total === 1 ? 'match' : 'matches',
            $what,
            $search ? self::byWhat($symbols) : '',
            count($symbols) < $total ? sprintf(' (showing %d — narrow it, or use --json)', count($symbols)) : '',
        );

        $columns = ['symbol', 'pack', 'signature', 'what it does'];

        return $header . (new Table($search ? [...$columns, 'match'] : $columns, $rows))->render()
            . "\nOne class in full: lava api <ClassName>\n";
    }

    /**
     * ` (2 by name, 1 in prose)`, or nothing when they are all one kind.
     *
     * A count is what an agent branches on, and a word appearing in a sentence
     * is not the same answer as a thing being called that.
     *
     * @param list<array<string, mixed>> $symbols
     */
    private static function byWhat(array $symbols): string
    {
        $byName = count(array_filter($symbols, static fn (array $s): bool => ($s['matched'] ?? null) === 'name'));
        $byProse = count($symbols) - $byName;

        if ($byName === 0 || $byProse === 0) {
            return '';
        }

        return sprintf(' (%d by name, %d in prose)', $byName, $byProse);
    }

    /**
     * `readonly string $appDir`, as the class declares it.
     *
     * @param array<mixed> $property one entry of a symbol's `properties`, as the payload carries it
     */
    private static function property(array $property): string
    {
        return trim(
            (($property['static'] ?? false) === true ? 'static ' : '')
            . (($property['readonly'] ?? false) === true ? 'readonly ' : '')
            . self::text($property['type'] ?? null)
            . ' $' . self::text($property['name'] ?? null),
        );
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
        // `abstract` leads the kind, because it changes what the reader does with
        // the class: extend it, rather than call it.
        $kind = (($symbol['abstract'] ?? false) === true ? 'abstract ' : '') . self::text($symbol['kind'] ?? null);
        $out = self::text($symbol['name'] ?? null) . "  ({$kind}, " . self::text($symbol['pack'] ?? null) . ")\n"
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

        $constructor = $symbol['constructor'] ?? null;
        if (is_string($constructor)) {
            $out .= "\nconstruct: new " . self::short(self::text($symbol['name'] ?? null))
                . '(' . self::constructorArguments($constructor) . ")\n";
        }

        $properties = is_array($symbol['properties'] ?? null) ? $symbol['properties'] : [];
        $rows = [];
        foreach ($properties as $property) {
            if (is_array($property)) {
                $rows[] = [self::property($property), self::text($property['summary'] ?? null)];
            }
        }
        if ($rows !== []) {
            $out .= "\n" . (new Table(['property', 'what it holds'], $rows))->render();
        }

        $methods = is_array($symbol['methods'] ?? null) ? $symbol['methods'] : [];
        $rows = [];
        foreach ($methods as $method) {
            if (is_array($method)) {
                $rows[] = [self::text($method['signature'] ?? null), self::text($method['summary'] ?? null)];
            }
        }
        if ($rows !== []) {
            $out .= "\n" . (new Table(['method', 'what it does'], $rows))->render();
        } elseif ($properties === [] && $constants === [] && $cases === [] && !is_string($constructor)) {
            // Nothing at all to list is a real answer for a marker interface, and
            // it has to read as one: an empty table under a class that HAS
            // properties is what made a reader think it had no API.
            $out .= "\nNo public members: this type is named in signatures rather than called.\n";
        }

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

    /**
     * `__construct(string $a, int $b)` as `string $a, int $b`, so the line reads
     * as the `new` a reader writes rather than as a method they call.
     */
    private static function constructorArguments(string $constructor): string
    {
        $open = strpos($constructor, '(');
        $close = strrpos($constructor, ')');

        return $open === false || $close === false || $close <= $open
            ? $constructor
            : substr($constructor, $open + 1, $close - $open - 1);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
