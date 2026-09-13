<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Container\Container;
use Lava\Core\Map\ProjectMap;
use Lava\Core\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * The services section of the map lists what each registration DECLARES, so a
 * factory that picks its class by environment does not make the committed map
 * stale everywhere but where it was written.
 *
 * Found by an outside build (Lava Notes, B10): the section recorded the class a
 * resolution produced, and `lava check --strict --env=prod` failed on a map that
 * was current.
 */
final class ProjectMapServicesTest extends TestCase
{
    public function testAFactoryThatBranchesOnTheEnvironmentDoesNotMoveTheFingerprint(): void
    {
        $booted = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $booted);

        $maps = [];
        foreach (['dev' => new \ArrayObject(), 'prod' => new \ArrayIterator()] as $env => $store) {
            $container = new Container();
            $container->singleton('app.store', static fn (): \Countable => $store);
            // Resolved, as ValidateWiring resolves every id at every real boot:
            // the resolved class is exactly what used to leak into the map.
            $container->get('app.store');

            $maps[$env] = ProjectMap::of(new App(
                $booted->appDir,
                $env,
                $booted->config,
                $booted->features,
                $container,
                $booted->problems,
                $booted->router,
            ));
        }

        self::assertSame($maps['dev']->fingerprint(), $maps['prod']->fingerprint());
        self::assertSame('Countable', $maps['prod']->services[0]['class']);
    }

    public function testTheClassColumnIsTheDeclaredReturnTypeOrNothing(): void
    {
        $booted = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $booted);

        $container = new Container();
        $container->singleton('typed', static fn (): \ArrayObject => new \ArrayObject());
        $container->factory('untyped', static fn () => new \ArrayObject());
        $container->value('plain', 'a string');
        $container->alias('pointer', 'typed');

        $map = ProjectMap::of(new App(
            $booted->appDir,
            $booted->env,
            $booted->config,
            $booted->features,
            $container,
            $booted->problems,
            $booted->router,
        ));

        self::assertSame(
            ['typed' => 'ArrayObject', 'untyped' => null, 'plain' => null, 'pointer' => null],
            array_column($map->services, 'class', 'id'),
        );
    }
}
