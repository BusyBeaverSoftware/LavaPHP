<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Lava\Core\Map\MapSection;
use Lava\Core\Map\ProjectMap;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Modules\ProvidesMapSection;
use Lava\Core\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * A pack adds a section of its own to AGENTS.md — the seam lavaphp/events lists
 * its listeners through — and an app whose packs add nothing keeps its map.
 */
final class ProjectMapPackSectionTest extends TestCase
{
    /** @param array<string, Module> $modules */
    private static function map(array $modules): ProjectMap
    {
        $booted = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $booted);

        return ProjectMap::of(new App(
            $booted->appDir,
            $booted->env,
            $booted->config,
            $booted->features,
            $booted->container,
            $booted->problems,
            $booted->router,
            $booted->globalMiddleware,
            $booted->moduleRefs,
            $booted->packs,
            $booted->dotEnv,
            $booted->envFromFile,
            $modules,
        ));
    }

    private static function module(?MapSection $section): Module
    {
        return new class ($section) implements Module, ProvidesMapSection {
            public function __construct(private readonly ?MapSection $section)
            {
            }

            public function pack(): PackInfo
            {
                return PackInfo::of('lavaphp/fixture-pack', 'fixture_pack');
            }

            public function register(Container $container, AppContext $ctx): void
            {
            }

            public function mapSection(App $app): ?MapSection
            {
                return $this->section;
            }
        };
    }

    public function testASectionAppearsInTheDocumentAndInTheFingerprint(): void
    {
        $booted = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $booted);
        $without = self::map([]);

        self::assertSame(ProjectMap::of($booted)->fingerprint(), $without->fingerprint());
        self::assertArrayNotHasKey('pack_sections', $without->sections(), 'An app whose packs add nothing keeps its fingerprint.');

        $with = self::map(['App\FixturePack' => self::module(new MapSection(
            'Events',
            'Which listeners each event reaches, in the order they run.',
            ['event', 'listeners'],
            [['App\Events\TaskDone', 'App\Listeners\LogIt']],
        ))]);

        self::assertNotSame($without->fingerprint(), $with->fingerprint());
        self::assertStringContainsString(
            "\n## Events (1)\n\nWhich listeners each event reaches, in the order they run. Listed by lavaphp/fixture-pack.\n\n",
            $with->markdown(),
        );
        self::assertStringContainsString('App\Events\TaskDone', $with->markdown());
    }

    public function testAModuleWithNothingToListAddsNothing(): void
    {
        self::assertSame(self::map([])->fingerprint(), self::map(['App\FixturePack' => self::module(null)])->fingerprint());
    }

    public function testARowWithTheWrongNumberOfCellsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MapSection('Events', 'Listeners.', ['event', 'listeners'], [['only one cell']]);
    }
}
