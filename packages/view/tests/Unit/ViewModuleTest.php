<?php

declare(strict_types=1);

namespace Lava\View\Tests\Unit;

use Lava\Core\Features\FeatureScope;
use Lava\Core\Boot\AppContext;
use Lava\Core\Config\Config;
use Lava\Core\Container\Container;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Routing\Router;
use Lava\Core\Routing\UrlGenerator;
use Lava\View\Problem\ViewDirMissing;
use Lava\View\Tests\Support\Flags;
use Lava\View\Tests\Support\Templates;
use Lava\View\ViewModule;
use Lava\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * How the module reads config — the part a boot test cannot isolate.
 *
 * Over HTTP the fixture proves the pack works when it is configured the way the
 * fixture configures it. These are the branches the fixture never takes: a
 * missing directory, a relative path, the defaults, and prod's cache. Each is a
 * decision with a wrong answer that would look like a working app until it met
 * the environment it was written for.
 *
 * The config array is keyed the way the loader keys it — `view.path`, not
 * `path` — because that is what reaches `Config`. A test that passed the
 * unprefixed key would silently take every default and pass while proving
 * nothing.
 */
final class ViewModuleTest extends TestCase
{
    private Templates $templates;

    protected function setUp(): void
    {
        $this->templates = Templates::make([
            // Two real template directories, so the default ('views') and an
            // explicit relative path ('tpl') can both be exercised without one
            // test's config leaking into another's fixture.
            'views/page.twig' => '<p>{{ title }}</p>',
            'tpl/sub.twig' => '<p>sub</p>',
        ]);
    }

    protected function tearDown(): void
    {
        $this->templates->remove();
    }

    /**
     * The module registered into a container that already holds a UrlGenerator.
     *
     * The generator is registered FIRST because the module's factory resolves
     * it by id — which is the whole point of the factory indirection: at
     * `register()` time in a real boot there is no router yet, and the lookup
     * happens later, from ValidateWiring. Registering it here stands in for
     * that later moment.
     *
     * @param array<string, mixed> $config as the loader keys it, e.g. ['view.path' => 'tpl']
     */
    private function register(array $config = [], string $env = 'dev'): Container
    {
        $router = new Router();
        $router->get('/users/{id:int}', 'users.show')->handler('not-a-real-handler');
        $router->finalize(new ProblemReport());

        $container = new Container();
        $container->singleton(UrlGenerator::class, static fn (): UrlGenerator => new UrlGenerator($router));
        // Core registers the flag scope before any module runs; the renderer
        // reads it when it is built, so this stand-in registers it as well.
        $container->singleton(FeatureScope::class, static fn (): FeatureScope => new FeatureScope(Flags::of()));

        (new ViewModule())->register(
            $container,
            new AppContext($this->templates->dir(), $env, new Config($config), Flags::of()),
        );

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function renderer(array $config = [], string $env = 'dev'): ViewRenderer
    {
        $renderer = $this->register($config, $env)->get(ViewRenderer::class);
        self::assertInstanceOf(ViewRenderer::class, $renderer);

        return $renderer;
    }

    public function testThePackManifestDeclaresItsConfigFileAndGate(): void
    {
        // `configFiles: ['view']` is a STEM — the loader appends `.php`, the
        // same convention PackInfo uses everywhere. A manifest naming
        // 'view.php' would be read as `config/view.php.php` and silently find
        // nothing, so the stem is what is asserted.
        $pack = (new ViewModule())->pack();

        self::assertSame('lava/view', $pack->package);
        self::assertSame('views', $pack->feature);
        self::assertSame(['view'], $pack->configFiles);
    }

    public function testTemplatesComeFromViewsByDefault(): void
    {
        self::assertSame($this->templates->dir() . '/views', $this->renderer()->templateDir());
    }

    public function testARelativePathIsResolvedAgainstTheAppNotTheWorkingDirectory(): void
    {
        // `lava serve` and a request under FPM have different working
        // directories. A path resolved against the cwd works in development and
        // 404s in production, which is the failure mode this rule exists to
        // make impossible.
        //
        // The first assertion is the claim; the second is what keeps it from
        // being vacuous — if the app directory ever happened to BE the working
        // directory, a cwd-resolved path would satisfy the first one too.
        self::assertSame($this->templates->dir() . '/tpl', $this->renderer(['view.path' => 'tpl'])->templateDir());
        self::assertNotSame(getcwd(), $this->templates->dir());
    }

    public function testAnAbsolutePathIsUsedAsGiven(): void
    {
        self::assertSame(
            $this->templates->dir(),
            $this->renderer(['view.path' => $this->templates->dir()])->templateDir(),
        );
    }

    public function testAMissingDirectoryIsABootProblemNamingTheKeyAndThePath(): void
    {
        // Boot-time, not request-time: every render would fail identically, so
        // N request errors collapse into one message with the path to create.
        try {
            $this->register(['view.path' => 'nope']);
        } catch (ViewDirMissing $problem) {
            self::assertSame('view_dir_missing', $problem->code());
            self::assertStringContainsString($this->templates->dir() . '/nope', $problem->getMessage());
            // The fix has to name the config key, or the reader knows the
            // directory is wrong and not where the directory is set.
            self::assertStringContainsString('config/view.php', $problem->fix);
            self::assertSame($this->templates->dir(), $problem->context['app_dir']);

            return;
        }

        self::fail('a missing template directory should fail the boot');
    }

    public function testDebugDefaultsToOnOutsideProduction(): void
    {
        // An app gets live templates while it is being written without
        // configuring anything, and a compiled cache in production without
        // remembering to turn one on. LAVA_ENV is the same switch that decides
        // the logger's level.
        self::assertTrue($this->renderer(env: 'dev')->environment()->isDebug());
        self::assertTrue($this->renderer(env: 'staging')->environment()->isDebug());
        self::assertFalse($this->renderer(env: 'prod')->environment()->isDebug());
    }

    public function testDebugCanBeSetExplicitlyEitherWay(): void
    {
        self::assertFalse($this->renderer(['view.debug' => false], env: 'dev')->environment()->isDebug());
        self::assertTrue($this->renderer(['view.debug' => true], env: 'prod')->environment()->isDebug());
    }

    public function testTheCacheDirectoryDefaultsUnderTheAppAndIsOffInDebug(): void
    {
        // Default cache path, resolved against the app like every other path.
        $prod = $this->renderer(env: 'prod');
        self::assertSame($this->templates->dir() . '/var/views', $prod->environment()->getCache());

        // And in debug the same configured directory is ignored, because a
        // stale compiled template is the bug that looks like nothing happening.
        self::assertFalse($this->renderer(env: 'dev')->environment()->getCache());
    }

    public function testTheRendererIsASingleton(): void
    {
        // One Twig environment per app, not one per handler. Two would compile
        // every template twice and — worse — let a handler that added a filter
        // to "its" environment see it silently missing on the next request.
        $container = $this->register();

        self::assertSame($container->get(ViewRenderer::class), $container->get(ViewRenderer::class));
    }

    public function testAWrongServiceUnderTheUrlGeneratorIdIsCaughtAtResolution(): void
    {
        // An app that registered something else under the id gets a named
        // problem at boot rather than "undefined method url() on string" on
        // whichever page renders a link first. This is why the module does not
        // accept a `UrlGenerator` parameter: a typed parameter would be checked
        // by PHP with a message naming no id and no site.
        $container = new Container();
        $container->singleton(UrlGenerator::class, static fn (): string => 'not a generator');

        (new ViewModule())->register(
            $container,
            new AppContext($this->templates->dir(), 'dev', new Config([]), Flags::of()),
        );

        $this->expectException(InvalidConfig::class);
        $container->get(ViewRenderer::class);
    }
}
