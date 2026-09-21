<?php

declare(strict_types=1);

namespace Lava\Core\Map;

use Composer\InstalledVersions;

/**
 * The framework's own public API, compiled by reflecting over the installed
 * packages — what `lava api` answers from.
 *
 * **Why this exists.** Three outside builds of a real application by AI agents
 * produced 70 verdicts on "missing" features, and 33 of them were capabilities
 * that already shipped and the agent did not find. Grep answers "I did not see
 * it"; this answers "the framework has it, here is the signature" — or, because
 * every class is accounted for ({@see ApiSurface}), a trustworthy "it has
 * nothing of that name".
 *
 * **Why nothing is written to disk.** `lava map` compiles the APP's
 * declarations into a fingerprinted `AGENTS.md`; putting framework facts in
 * that document would make every app's committed map stale on every framework
 * upgrade — the failure `lava map` was just fixed to stop making. And a shipped
 * index can be wrong about the code beside it the moment someone patches a
 * method, while reflection cannot be: it reads the installed code itself. The
 * whole sweep measures in tens of milliseconds, so there is nothing to cache.
 *
 * @phpstan-type ApiMethod array{name: string, static: bool, abstract: bool, signature: string, summary: string|null, at: string}
 * @phpstan-type ApiProperty array{name: string, type: string|null, readonly: bool, static: bool, summary: string|null}
 * @phpstan-type ApiConstant array{name: string, value: string}
 * @phpstan-type ApiSymbol array{pack: string, name: string, kind: string, abstract: bool, at: string, summary: string|null, extends: string|null, implements: list<string>, cases: list<string>, constants: list<ApiConstant>, constructor: string|null, properties: list<ApiProperty>, methods: list<ApiMethod>, example: string|null}
 * @phpstan-type ApiPack array{pack: string, package: string, version: string|null, feature: string|null, enabled: bool|null, types: int, methods: int}
 */
final readonly class ApiIndex
{
    /**
     * @param list<ApiPack> $packs
     * @param array<string, ApiSymbol> $symbols class name => its record, in pack then file order
     * @param array<string, string> $excluded class name => why it is not API
     * @param array<string, string> $leaks class name => the indexed signature that names an @internal type
     */
    private function __construct(
        public array $packs,
        public array $symbols,
        public array $excluded,
        public array $leaks,
    ) {
    }

    /**
     * Every `lavaphp/*` package installed beside this one that declares a
     * surface.
     *
     * Composer is asked rather than a hard-coded list: core must not name the
     * packs, and a pack that is installed but switched off still has an API an
     * agent needs to read — hiding it behind its gate would reproduce the exact
     * failure this index exists to fix.
     *
     * @return list<ApiSurface>
     */
    public static function surfaces(): array
    {
        $surfaces = [new CoreApiSurface()];

        if (!class_exists(InstalledVersions::class)) {
            // An app assembled without Composer's generated files: core is the
            // one package whose surface we can reach by autoloading alone.
            return $surfaces;
        }

        $packages = InstalledVersions::getInstalledPackages();
        sort($packages);
        foreach ($packages as $package) {
            if (!str_starts_with($package, 'lavaphp/') || $package === 'lavaphp/core') {
                continue;
            }
            $surface = self::surfaceOf($package);
            if ($surface !== null) {
                $surfaces[] = $surface;
            }
        }

        return $surfaces;
    }

    /**
     * A package's surface class, by the one naming convention the framework
     * already uses for modules: `lavaphp/http-client` holds
     * `Lava\HttpClient\HttpClientApiSurface`. The monorepo root
     * (`lavaphp/lavaphp`) and the skeleton (`lavaphp/app`) declare none, and
     * fall out here rather than needing a list of exceptions.
     */
    private static function surfaceOf(string $package): ?ApiSurface
    {
        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', substr($package, strlen('lavaphp/')))));
        foreach (["Lava\\{$studly}\\{$studly}ApiSurface", "Lava\\{$studly}\\Map\\{$studly}ApiSurface"] as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $surface = new $class();
            if ($surface instanceof ApiSurface) {
                return $surface;
            }
        }

        return null;
    }

    /**
     * Compiles the index.
     *
     * @param list<ApiSurface> $surfaces
     * @param string $appDir the app the paths are rendered relative to, so a
     *        symbol reads `core:src/Features/Flag.php` however core is installed
     * @param array<string, bool> $enabled package => whether its gate resolved on,
     *        when an app booted; a package absent from the map reports null
     */
    public static function of(array $surfaces, string $appDir, array $enabled = []): self
    {
        $symbols = [];
        $excluded = [];
        $packs = [];

        foreach ($surfaces as $surface) {
            $examples = $surface->examples();
            $types = 0;
            $methods = 0;

            foreach (self::classesIn($surface) as $relative => $class) {
                $reason = self::notApi($surface, $relative, $class);
                if ($reason !== null) {
                    $excluded[$class] = $reason;
                    continue;
                }

                $symbol = self::symbol($surface, $class, $appDir, $examples[$class] ?? null);
                $symbols[$class] = $symbol;
                $types++;
                $methods += count($symbol['methods']);
            }

            $packs[] = [
                'pack' => $surface->pack(),
                'package' => $surface->package(),
                'version' => self::version($surface->package()),
                'feature' => $surface->feature(),
                'enabled' => $enabled[$surface->package()] ?? null,
                'types' => $types,
                'methods' => $methods,
            ];
        }

        [$symbols, $leaks] = self::promoteReachable($symbols, $excluded, $surfaces, $appDir);

        // The pack counts are taken before promotion on purpose: they describe
        // what each pack offers as its own surface, and a type pulled in because
        // a signature names it is a detail of the payload, not of the pack.
        return new self($packs, $symbols, $excluded, $leaks);
    }

    /**
     * Why this class is not part of the API, or null when it is.
     *
     * The order is the order a reader would apply: a trait is never API; then
     * `@internal` on the class, which the author wrote about this class and so
     * outranks anything positional; then a declared extension point, which is
     * API wherever it sits; then the pack's path rules. Each answer is a
     * sentence rather than a code, because it is what the drift guard prints
     * when a new class lands somewhere unaccounted for.
     *
     * @param class-string $class
     */
    private static function notApi(ApiSurface $surface, string $relative, string $class): ?string
    {
        if (trait_exists($class)) {
            return 'a trait is an implementation detail of the classes that use it';
        }

        $doc = (new \ReflectionClass($class))->getDocComment();
        if (is_string($doc) && str_contains($doc, '@internal')) {
            return 'marked @internal';
        }

        if (isset($surface->extensionPoints()[$class])) {
            return null;
        }

        foreach ($surface->exclusions() as $prefix => $reason) {
            if (str_starts_with($relative, $prefix)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Every class, interface and enum under a pack's `src/`, keyed by its path
     * relative to that root.
     *
     * Sorted, because the payload's order is part of what a consumer diffs and
     * a directory iterator's order is the filesystem's.
     *
     * @return array<string, class-string>
     */
    private static function classesIn(ApiSurface $surface): array
    {
        $root = $surface->sourceRoot();
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $classes = [];
        foreach ($files as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $class = $surface->namespacePrefix() . str_replace('/', '\\', substr($relative, 0, -strlen('.php')));
            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $classes[$relative] = $class;
            }
        }

        return $classes;
    }

    /**
     * One class as the payload carries it.
     *
     * @param class-string $class
     * @return ApiSymbol
     */
    private static function symbol(ApiSurface $surface, string $class, string $appDir, ?string $example): array
    {
        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        $abstract = $reflection->isAbstract() && !$reflection->isInterface();

        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
            // Declared here only: an inherited method belongs to the class that
            // declares it, and repeating it would list the same signature under
            // every subclass. `extends` and `implements` are how a reader
            // follows it. Magic methods are the object's mechanics, not its API,
            // and a constructor is carried on its own below.
            if ($method->getDeclaringClass()->getName() !== $class || str_starts_with($method->getName(), '__')) {
                continue;
            }
            // A protected method is an implementation detail — unless it is
            // abstract on a class an app extends, where it is the contract
            // itself: `AppCommand::inspect()` is the one method an app command
            // must write, and a base class listed without it teaches nothing.
            if (!$method->isPublic() && !($abstract && $method->isAbstract())) {
                continue;
            }
            $methodDoc = $method->getDocComment();
            if (is_string($methodDoc) && str_contains($methodDoc, '@internal')) {
                // One method can be plumbing inside a class that is API:
                // `Router::finalize()` belongs to boot, not to app code, while
                // everything else on Router is exactly what an app calls.
                continue;
            }
            $methods[] = [
                'name' => $method->getName(),
                'static' => $method->isStatic(),
                'abstract' => $method->isAbstract(),
                'signature' => self::signature($method),
                'summary' => self::summary($method->getDocComment()),
                'at' => self::at($method->getFileName(), $appDir, $method->getStartLine()),
            ];
        }

        // The constructor, separately, because `new` is not a method call and an
        // index that hid it read as "a class you receive" for every class you
        // actually construct — `LavaProblem` among them, which an app subclasses
        // to raise a problem of its own (Lava Notes round 4, R4-G9).
        $made = $reflection->getConstructor();
        $constructor = $made !== null && $made->isPublic() && $made->getDeclaringClass()->getName() === $class
            ? self::signature($made)
            : null;

        // Public properties, which a framework built on `final readonly` value
        // objects keeps most of its surface in: without these, `AppContext` — four
        // promoted properties and no methods — printed "(none)", and the reader
        // guessed a name and got a failed boot (round 4, R4-B3).
        $properties = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $propertyDoc = $property->getDocComment();
            if (is_string($propertyDoc) && str_contains($propertyDoc, '@internal')) {
                continue;
            }
            $properties[] = [
                'name' => $property->getName(),
                'type' => self::type($property->getType()),
                'readonly' => $property->isReadOnly(),
                'static' => $property->isStatic(),
                // A promoted property is documented in the constructor's
                // `@param`, which is where this framework's value objects say
                // what each field means.
                'summary' => self::summary($propertyDoc) ?? self::promotedSummary($made, $property->getName()),
            ];
        }

        $constants = [];
        foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $class || $constant instanceof \ReflectionEnumBackedCase) {
                continue;
            }
            $constants[] = ['name' => $constant->getName(), 'value' => self::render($constant->getValue())];
        }

        $cases = [];
        if (is_a($class, \UnitEnum::class, true)) {
            foreach ((new \ReflectionEnum($class))->getCases() as $case) {
                $cases[] = $case->getName();
            }
        }

        $parent = $reflection->getParentClass();

        return [
            'pack' => $surface->pack(),
            'name' => $class,
            'kind' => match (true) {
                $reflection->isEnum() => 'enum',
                $reflection->isInterface() => 'interface',
                default => 'class',
            },
            'abstract' => $abstract,
            'at' => self::at($file, $appDir, null),
            'summary' => self::summary($reflection->getDocComment()),
            'extends' => $parent === false ? null : $parent->getName(),
            'implements' => $reflection->getInterfaceNames(),
            'cases' => $cases,
            'constants' => $constants,
            'constructor' => $constructor,
            'properties' => $properties,
            'methods' => $methods,
            'example' => $example,
        ];
    }

    /**
     * Indexes every `Lava\` type the indexed surface names but the payload
     * lacks, and reports the ones it cannot.
     *
     * Without this the index would answer a question with a type the reader
     * cannot then look up: `Router::match()` returns `Matched|RouteNotFound`,
     * and `RouteNotFound` lives under `Problem/`, which is excluded wholesale.
     * A type named by the API IS part of the API, whatever directory it sits
     * in — except one marked `@internal`, which is a contradiction the author
     * has to resolve, so it is collected for the guard rather than papered over.
     *
     * "Names" means a method signature, a constructor, or a public property's
     * type: all three are ways an app meets a type, and a property-blind scan
     * would leave a `final readonly` value object's fields unaccounted for.
     *
     * @param array<string, ApiSymbol> $symbols
     * @param array<string, string> $excluded
     * @param list<ApiSurface> $surfaces
     * @return array{0: array<string, ApiSymbol>, 1: array<string, string>}
     */
    private static function promoteReachable(array $symbols, array $excluded, array $surfaces, string $appDir): array
    {
        $byPrefix = [];
        foreach ($surfaces as $surface) {
            $byPrefix[$surface->namespacePrefix()] = $surface;
        }

        $leaks = [];
        // A promoted type can itself name another, so this runs until it settles
        // — bounded by the number of classes, and in practice one extra pass.
        do {
            $added = false;
            foreach ($symbols as $symbol) {
                foreach (self::namedTypesIn($symbol) as $named => $where) {
                    if (isset($symbols[$named])) {
                        continue;
                    }
                    if (($excluded[$named] ?? null) === 'marked @internal') {
                        $leaks[$named] = $where;
                        continue;
                    }
                    $surface = self::surfaceFor($named, $byPrefix);
                    if ($surface === null || !class_exists($named) && !interface_exists($named) && !enum_exists($named)) {
                        continue;
                    }
                    $symbols[$named] = self::symbol($surface, $named, $appDir, null);
                    $added = true;
                }
            }
        } while ($added);

        ksort($symbols);
        ksort($leaks);

        return [$symbols, $leaks];
    }

    /**
     * Every `Lava\` type one symbol exposes, mapped to where it exposes it.
     *
     * First mention wins, because the guard's message only needs one place to
     * send the author — and a type named by three methods is one decision, not
     * three.
     *
     * @param ApiSymbol $symbol
     * @return array<string, string> type => `Class::method()`, `Class::__construct()` or `Class::$property`
     */
    private static function namedTypesIn(array $symbol): array
    {
        $found = [];

        if ($symbol['constructor'] !== null) {
            foreach (self::lavaNamesIn($symbol['constructor']) as $named) {
                $found[$named] ??= $symbol['name'] . '::__construct()';
            }
        }

        foreach ($symbol['properties'] as $property) {
            if ($property['type'] === null) {
                continue;
            }
            foreach (self::lavaNamesIn($property['type']) as $named) {
                $found[$named] ??= $symbol['name'] . '::$' . $property['name'];
            }
        }

        foreach ($symbol['methods'] as $method) {
            foreach (self::lavaNamesIn($method['signature']) as $named) {
                $found[$named] ??= $symbol['name'] . '::' . $method['name'] . '()';
            }
        }

        return $found;
    }

    /**
     * @param array<string, ApiSurface> $byPrefix
     */
    private static function surfaceFor(string $class, array $byPrefix): ?ApiSurface
    {
        foreach ($byPrefix as $prefix => $surface) {
            if (str_starts_with($class, $prefix)) {
                return $surface;
            }
        }

        return null;
    }

    /**
     * The `Lava\…` type names a rendered signature mentions.
     *
     * @return list<string>
     */
    private static function lavaNamesIn(string $signature): array
    {
        preg_match_all('/Lava\\\\[A-Za-z0-9_\\\\]+/', $signature, $matches);

        // Keyed, then re-listed: that dedupes while keeping the order the
        // signature reads in, which array_unique's preserved keys would not.
        $names = [];
        foreach ($matches[0] as $name) {
            $names[$name] = true;
        }

        return array_keys($names);
    }

    /**
     * A method as it is written, fully qualified: what an agent has to type.
     *
     * The modifiers lead, because `abstract protected inspect(…)` is an
     * instruction — write this — while `inspect(…)` alone reads as something to
     * call.
     */
    private static function signature(\ReflectionMethod $method): string
    {
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $type = self::type($parameter->getType());
            $default = '';
            if ($parameter->isDefaultValueAvailable()) {
                $default = ' = ' . self::render($parameter->getDefaultValue());
            }
            $parameters[] = trim(
                ($type === null ? '' : $type . ' ')
                . ($parameter->isVariadic() ? '...' : '')
                . '$' . $parameter->getName()
                . $default,
            );
        }

        $returns = self::type($method->getReturnType());

        return ($method->isAbstract() ? 'abstract ' : '')
            . ($method->isProtected() ? 'protected ' : '')
            . ($method->isStatic() ? 'static ' : '')
            . $method->getName() . '(' . implode(', ', $parameters) . ')'
            . ($returns === null ? '' : ': ' . $returns);
    }

    /**
     * What a constructor's `@param` says about one promoted property.
     *
     * A promoted property carries no docblock of its own, so this is where a
     * value object's fields are actually documented: `@param list<string>
     * $globalMiddleware class-strings from app/Middleware.php`. Only the first
     * line is taken — the rest of a wrapped `@param` is reasoning, and this is a
     * column in a table.
     */
    private static function promotedSummary(?\ReflectionMethod $constructor, string $property): ?string
    {
        $doc = $constructor?->getDocComment();
        if (!is_string($doc)) {
            return null;
        }

        $pattern = '/@param\s+\S+\s+\$' . preg_quote($property, '/') . '\s+([^\r\n]+)/';
        if (preg_match($pattern, $doc, $matches) !== 1) {
            return null;
        }

        $text = trim((string) preg_replace('#\s*\*/?\s*$#D', '', $matches[1]));

        return $text === '' ? null : mb_strimwidth($text, 0, 200, '…');
    }

    private static function type(?\ReflectionType $type): ?string
    {
        return match (true) {
            $type instanceof \ReflectionNamedType => ($type->allowsNull() && $type->getName() !== 'null' && $type->getName() !== 'mixed' ? '?' : '') . $type->getName(),
            $type instanceof \ReflectionUnionType => implode('|', array_map(
                static fn (\ReflectionType $part): string => self::type($part) ?? 'mixed',
                $type->getTypes(),
            )),
            $type instanceof \ReflectionIntersectionType => implode('&', array_map(
                static fn (\ReflectionType $part): string => self::type($part) ?? 'mixed',
                $type->getTypes(),
            )),
            default => null,
        };
    }

    /**
     * A default value as one line of PHP.
     *
     * `var_export` is the only total renderer for a mixed value, and its
     * multi-line arrays would break the one-line signature, so they are
     * collapsed. `NULL` is lowercased for the same reason the types are
     * qualified: a signature is copied, and `null` is how PHP is written here.
     */
    private static function render(mixed $value): string
    {
        $exported = var_export($value, true);
        $exported = (string) preg_replace('/\s+/', ' ', $exported);
        $exported = str_replace(['array ( )', 'array ( ', ' )'], ['[]', '[', ']'], $exported);

        return $value === null ? 'null' : $exported;
    }

    /**
     * A docblock's first sentence, or null.
     *
     * The first sentence is what a search result can show and what a reader
     * skims; the rest of a docblock in this framework is the reasoning, which
     * belongs in the file rather than in a list of 600 rows.
     */
    private static function summary(string|false $doc): ?string
    {
        if (!is_string($doc)) {
            return null;
        }

        $lines = [];
        foreach (explode("\n", $doc) as $line) {
            $line = trim((string) preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line));
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line === '' && $lines !== []) {
                break;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $text = trim(implode(' ', $lines));
        if ($text === '') {
            return null;
        }

        // Up to the first sentence end that is followed by a space, so
        // `app/Routes.php.` inside a sentence does not cut it short.
        if (preg_match('/^(.+?[.?!])(\s|$)/u', $text, $matches) === 1) {
            $text = $matches[1];
        }

        return mb_strimwidth($text, 0, 200, '…');
    }

    /**
     * `core:src/Features/Flag.php:88`, the spelling the map already uses.
     *
     * Reflection reports a file or a line as `false` for anything it did not
     * read from a file — an internal class, or one defined in eval'd code —
     * which is "unknown" rather than a number, so both are taken as absent.
     */
    private static function at(string|false $file, string $appDir, int|false|null $line): string
    {
        if (!is_string($file)) {
            return '(unknown)';
        }

        return ProjectMap::relative($file, $appDir) . (is_int($line) ? ':' . $line : '');
    }

    /** What Composer installed for a package, or null when it cannot say. */
    private static function version(string $package): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion($package);
        } catch (\OutOfBoundsException) {
            return null;
        }
    }
}
