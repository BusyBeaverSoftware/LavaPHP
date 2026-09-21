<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Map\ApiIndex;
use Lava\Core\Map\ApiSurface;
use Lava\Core\Map\FrameworkReference;
use Lava\Core\Modules\Module;
use PHPUnit\Framework\TestCase;

/**
 * `lava api` cannot quietly omit a class — which is the whole reason to trust it.
 *
 * An index that merely lists what someone remembered to list answers "I did not
 * find it", and that answer is worth nothing: the failure this feature exists to
 * fix is an agent searching, finding nothing, and writing 690 lines of its own
 * because it concluded the framework had nothing. A closed surface turns the
 * same answer into "the framework does not have it".
 *
 * Closed means: every class under every package's `src/` is indexed, promoted
 * because an indexed signature names it, or excluded by a rule its pack
 * declares WITH a reason. There is no fourth state, and a new class in an
 * unaccounted-for namespace fails here rather than going missing in silence.
 */
final class ApiSurfaceTest extends TestCase
{
    public function testEveryClassLivesInADirectoryItsPackHasAccountedFor(): void
    {
        $index = self::index();
        $checked = 0;

        foreach (ApiIndex::surfaces() as $surface) {
            $declared = array_keys($surface->groups());
            foreach (array_keys($surface->exclusions()) as $excluded) {
                $declared[] = rtrim(explode('/', $excluded)[0], '/');
            }

            foreach (self::classesOf($surface) as $relative => $class) {
                $checked++;
                $group = str_contains($relative, '/') ? explode('/', $relative)[0] : '(root)';

                self::assertContains($group, $declared, implode("\n", [
                    "{$class} sits in a directory its pack has not accounted for: {$group}/",
                    'Nothing decided whether it is part of the framework\'s published API, so it',
                    'would have joined the index silently. Say which it is:',
                    '  ' . self::surfaceClass($surface) . '::groups()     — it is API, and this is what lives there',
                    '  ' . self::surfaceClass($surface) . '::exclusions() — it is not, and this is why',
                    'A single class inside an API directory is excluded with @internal on the class instead.',
                ]));

                self::assertTrue(
                    isset($index->symbols[$class]) || isset($index->excluded[$class]),
                    "{$class} is neither indexed nor excluded, which should be impossible",
                );
            }
        }

        self::assertGreaterThan(200, $checked, 'the sweep found almost nothing — is the source root still right?');
        self::assertNotSame([], $index->symbols, 'the index found no classes at all');
    }

    public function testNoIndexedSymbolIsMarkedInternal(): void
    {
        foreach (self::index()->symbols as $class => $symbol) {
            $doc = (new \ReflectionClass($class))->getDocComment();
            self::assertFalse(
                is_string($doc) && str_contains($doc, '@internal'),
                "{$class} is marked @internal and is still in the index — one of the two is wrong.",
            );
        }
    }

    /**
     * The closed-surface claim, stated as a test: an indexed signature may not
     * name a `Lava\` type the payload lacks.
     *
     * A method that returns something a reader cannot then look up sends them
     * back to grep, which is where they started. Promotion handles the ordinary
     * case (a problem class named by a return type); what lands here is a type
     * marked `@internal` and exposed anyway, which is a contradiction only the
     * author can resolve — by not exposing it, or by not calling it internal.
     */
    public function testTheIndexedSurfaceIsClosed(): void
    {
        $leaks = self::index()->leaks;

        self::assertSame([], $leaks, implode("\n", array_merge(
            ['a public signature names a type that is not in the index:'],
            array_map(
                static fn (string $type, string $where): string => "  {$where} names {$type}, which is marked @internal",
                array_keys($leaks),
                array_values($leaks),
            ),
        )));
    }

    /**
     * Everything the generated reference tells an app to use must be findable.
     *
     * `FrameworkReference` is what `lava map` writes into every app's
     * `AGENTS.md`, so it is the framework's own answer to "what do I extend, and
     * what do I call?". A fourth outside build followed it to
     * `Lava\Core\Console\Commands\AppCommand`, asked `lava api` about the class,
     * was told the framework had nothing — `Console/Commands/` is excluded
     * wholesale — and started writing its own. Two documents, one of them the
     * index, disagreeing about whether a class exists is the failure this whole
     * feature was built to end, so it is a test rather than a convention.
     */
    public function testEveryClassTheFrameworkReferenceNamesIsInTheIndex(): void
    {
        $index = self::index();
        preg_match_all('/Lava\\\\[A-Za-z0-9_\\\\]+/', FrameworkReference::markdown(), $matches);
        $named = array_values(array_unique($matches[0]));
        $checked = 0;

        foreach ($named as $class) {
            if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                // Prose can name a type an app writes, or a pack that is not
                // installed here; only real, loadable types are the index's job.
                continue;
            }
            $checked++;
            self::assertArrayHasKey($class, $index->symbols, implode("\n", [
                "the framework reference tells an app to use {$class}, and `lava api` cannot find it.",
                'An app reads that reference in its own AGENTS.md, so the two cannot disagree about what exists.',
                'Either index it — name it in the pack surface\'s extensionPoints() if it sits in an',
                'excluded directory — or stop naming it in FrameworkReference.',
            ]));
        }

        self::assertGreaterThan(10, $checked, 'the reference named almost no framework types — is markdown() still rendering?');
    }

    /**
     * The closed surface covers properties, not just signatures.
     *
     * A framework of `final readonly` value objects keeps most of its API in
     * promoted properties: `AppContext` is four of them and no methods, and while
     * the index was property-blind it answered "(none)" for the class and
     * "0 matches" for `appDir`. Both are the same bug as a missing method.
     */
    public function testAValueObjectsPropertiesAreIndexedAndTheirTypesAreToo(): void
    {
        $index = self::index();
        $withProperties = 0;

        foreach ($index->symbols as $class => $symbol) {
            $declared = array_filter(
                (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC),
                static fn (\ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === $class
                    && !str_contains((string) $p->getDocComment(), '@internal'),
            );
            self::assertSameSize(
                $declared,
                $symbol['properties'],
                "{$class} declares " . count($declared) . ' public properties and the index carries ' . count($symbol['properties']),
            );
            if ($symbol['properties'] !== []) {
                $withProperties++;
            }

            foreach ($symbol['properties'] as $property) {
                foreach (self::lavaTypesIn($property['type'] ?? '') as $type) {
                    self::assertArrayHasKey(
                        $type,
                        $index->symbols,
                        "{$class}::\${$property['name']} is typed {$type}, which the index does not carry — a reader cannot look it up.",
                    );
                }
            }
        }

        self::assertGreaterThan(20, $withProperties, 'almost nothing carries properties — is the reflection still reading them?');
    }

    /**
     * A class an app extends has to read as one: `abstract`, its constructor, and
     * the abstract methods a subclass must write.
     */
    public function testAnAbstractClassSaysSoAndShowsWhatASubclassMustWrite(): void
    {
        $index = self::index();

        $problem = $index->symbols[\Lava\Core\Problem\LavaProblem::class] ?? null;
        self::assertNotNull($problem, 'LavaProblem is what an app subclasses to raise a problem of its own');
        self::assertTrue($problem['abstract']);
        self::assertIsString($problem['constructor'], 'an app cannot subclass what it cannot construct');
        self::assertStringContainsString('string $fix', $problem['constructor']);
        self::assertContains('code', array_column($problem['methods'], 'name'), 'code() is the one method a subclass must write');

        $command = $index->symbols[\Lava\Core\Console\Commands\AppCommand::class] ?? null;
        self::assertNotNull($command, 'the generated reference tells an app to extend AppCommand');
        self::assertTrue($command['abstract']);
        $inspect = array_column($command['methods'], 'signature', 'name')['inspect'] ?? null;
        self::assertIsString($inspect, 'inspect() is protected and abstract — the contract, not plumbing');
        self::assertStringStartsWith('abstract protected inspect(', $inspect);
    }

    /**
     * Every `Lava\` type a string names.
     *
     * @return list<string>
     */
    private static function lavaTypesIn(string $type): array
    {
        preg_match_all('/Lava\\\\[A-Za-z0-9_\\\\]+/', $type, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function testEveryEntryPointIsIndexedAndCarriesItsExample(): void
    {
        $index = self::index();
        $entryPoints = 0;

        foreach (ApiIndex::surfaces() as $surface) {
            foreach (array_keys($surface->examples()) as $class) {
                $entryPoints++;
                self::assertArrayHasKey(
                    $class,
                    $index->symbols,
                    self::surfaceClass($surface) . " has an example for {$class}, which is not in the index — an example for something a reader cannot look up.",
                );
                self::assertNotNull(
                    $index->symbols[$class]['example'],
                    "{$class} is an entry point but its symbol carries no example.",
                );
            }
        }

        self::assertGreaterThan(5, $entryPoints, 'an index with no worked examples teaches nothing');
    }

    /**
     * A pack states its gate in two places — its module's `PackInfo` and its
     * surface — because the surface must answer without booting or instantiating
     * anything. Two copies drift unless something compares them, so this does.
     */
    public function testEachPackSurfaceAgreesWithItsModule(): void
    {
        $checked = 0;

        foreach (ApiIndex::surfaces() as $surface) {
            $module = self::moduleOf($surface);
            if ($module === null) {
                continue;
            }
            $checked++;
            $info = $module->pack();
            self::assertSame($info->package, $surface->package(), self::surfaceClass($surface) . ' names a different package than its module');
            self::assertSame($info->feature, $surface->feature(), self::surfaceClass($surface) . ' names a different feature than its module');
        }

        if ($checked === 0) {
            self::markTestSkipped('no pack is installed beside core, so there is no module to compare with');
        }
    }

    public function testThePacksReportVersionsAndCounts(): void
    {
        foreach (self::index()->packs as $pack) {
            self::assertNotSame('', $pack['pack']);
            self::assertStringStartsWith('lavaphp/', $pack['package']);
            self::assertGreaterThan(0, $pack['types'], "{$pack['package']} indexed no types at all");
            self::assertGreaterThan(0, $pack['methods'], "{$pack['package']} indexed no methods at all");
        }
    }

    private static function index(): ApiIndex
    {
        return ApiIndex::of(ApiIndex::surfaces(), dirname(__DIR__, 4));
    }

    /**
     * Every class, interface, enum and trait file under a surface's source root
     * — read from the filesystem rather than from the index, because the index
     * is what is being checked.
     *
     * @return array<string, class-string> path relative to src/ => the class
     */
    private static function classesOf(ApiSurface $surface): array
    {
        $root = $surface->sourceRoot();
        $classes = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $class = $surface->namespacePrefix() . str_replace('/', '\\', substr($relative, 0, -strlen('.php')));
            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $classes[$relative] = $class;
            }
        }

        ksort($classes);

        return $classes;
    }

    /** The module that owns a pack, by the framework's own naming rule, or null for core. */
    private static function moduleOf(ApiSurface $surface): ?Module
    {
        $prefix = $surface->namespacePrefix();
        $studly = trim(substr($prefix, strlen('Lava\\')), '\\');
        $class = $prefix . $studly . 'Module';

        if ($studly === 'Core' || !class_exists($class)) {
            return null;
        }

        $module = new $class();

        return $module instanceof Module ? $module : null;
    }

    private static function surfaceClass(ApiSurface $surface): string
    {
        return $surface::class;
    }
}
