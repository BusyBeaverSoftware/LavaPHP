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
        self::assertSame($about->data()['app'], ['dir' => $facts->appDir, 'env' => $facts->env]);
        self::assertSame($about->data()['packs'], $facts->packs());
        self::assertNotSame([], $facts->packs(), 'module-app names a pack, so the list is not trivially equal.');
    }
}
