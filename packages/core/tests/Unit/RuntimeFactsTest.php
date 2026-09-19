<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\Kernel;
use Lava\Core\Boot\RuntimeFacts;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestConsole;
use PHPUnit\Framework\TestCase;

/**
 * The facts `lava about` prints are a service app code can take (Lava Notes,
 * R2-G14), and the command reads the same service, so the two cannot differ.
 */
final class RuntimeFactsTest extends TestCase
{
    public function testAppCodeReadsExactlyTheFactsLavaAboutPrints(): void
    {
        $dir = TestApp::autoloadFixture('module-app');
        $app = TestApp::boot($dir);
        self::assertInstanceOf(App::class, $app);

        $facts = $app->container->get(RuntimeFacts::class);
        self::assertInstanceOf(RuntimeFacts::class, $facts);
        self::assertContains(RuntimeFacts::class, Kernel::CORE_SERVICES);

        $about = (new TestConsole($dir))->json('about');
        self::assertSame(0, $about->exitCode(), $about->output());
        self::assertSame($about->data()['php'], RuntimeFacts::php());
        self::assertSame($about->data()['framework_version'], RuntimeFacts::frameworkVersion());
        self::assertSame($about->data()['app'], ['dir' => $facts->appDir, 'env' => $facts->env]);
        self::assertSame($about->data()['packs'], $facts->packs($app));
        self::assertNotSame([], $facts->packs(), 'module-app names a pack, so the list is not trivially equal.');

        // The framework's own version is a fact about this install, so it is the
        // shape that is asserted, not a number this test would have to chase.
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) RuntimeFacts::frameworkVersion());

        // module-app's pack is written inside the fixture, so Composer has never
        // heard of it: null is the honest version, and the key is still there.
        $pack = self::pack($facts->packs(), 'lavaphp/demo-pack');
        self::assertNull($pack['version']);
        self::assertSame([], $pack['facts'], 'A module that implements no ProvidesFacts adds none.');
    }

    public function testAPacksOwnFactsAreAskedForWhenTheFactsAreReadAndNeverAtBoot(): void
    {
        // Lava Notes R3-G4. lavaphp/db reports pending migrations, which needs the
        // database — so nothing may be asked while the container is being built.
        if (!class_exists(\Lava\Db\DbModule::class)) {
            self::markTestSkipped('lavaphp/db is not installed, so no fixture can boot with a pack that reports facts');
        }

        $app = TestApp::boot(TestApp::autoloadFixture('packed-app'));
        self::assertInstanceOf(App::class, $app);
        $facts = $app->container->get(RuntimeFacts::class);
        self::assertInstanceOf(RuntimeFacts::class, $facts);

        $atBoot = self::pack($facts->packs(), 'lavaphp/db');
        self::assertSame([], $atBoot['facts'], 'Boot builds this service; asking a pack there would query the database.');
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $atBoot['version']);

        // Asked with the booted app in hand. packed-app configures no DSN, so the
        // fact is why it could not be counted — `about` still reports everything
        // else, which is the whole point of catching it.
        $asked = self::pack($facts->packs($app), 'lavaphp/db');
        self::assertSame('db_not_configured', $asked['facts']['error'] ?? null);
        self::assertIsString($asked['facts']['message'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $packs
     * @return array<string, mixed>
     */
    private static function pack(array $packs, string $package): array
    {
        foreach ($packs as $pack) {
            if (($pack['package'] ?? null) === $package) {
                return $pack;
            }
        }

        self::fail("No pack entry for {$package}.");
    }
}
