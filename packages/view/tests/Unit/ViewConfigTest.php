<?php

declare(strict_types=1);

namespace Lava\View\Tests\Unit;

use Lava\Core\Boot\AppContext;
use Lava\Core\Config\Config;
use Lava\Core\Container\Container;
use Lava\Core\Features\FeatureScope;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\ServiceNotRegistered;
use Lava\Core\Routing\Router;
use Lava\Core\Routing\UrlGenerator;
use Lava\View\Problem\TemplateNotFound;
use Lava\View\Problem\ViewDirMissing;
use Lava\View\Tests\Support\Flags;
use Lava\View\Tests\Support\Templates;
use Lava\View\ViewModule;
use Lava\View\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Extension\AbstractExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\TwigFilter;

/**
 * `view.namespaces` and `view.extensions` (Lava Notes, R2-G10 and R2-G11): theme
 * fallback without request state, and extensions installed at boot rather than
 * through a singleton whose factory happens to run before the first render.
 */
final class ViewConfigTest extends TestCase
{
    private Templates $templates;

    protected function setUp(): void
    {
        $this->templates = Templates::make([
            'views/card.twig' => 'shared card',
            'views/layout.twig' => 'shared layout',
            'views/whisper.twig' => '{{ "Hello There"|whisper }}',
            'themes/paper/card.twig' => 'paper card',
        ]);
    }

    protected function tearDown(): void
    {
        $this->templates->remove();
    }

    /**
     * The module registered as ViewModuleTest registers it: into a container
     * that already holds what core registers before any module runs.
     *
     * @param array<string, mixed> $config as the loader keys it
     */
    private function container(array $config): Container
    {
        $router = new Router();
        $router->finalize(new ProblemReport());

        $container = new Container();
        $container->singleton(UrlGenerator::class, static fn (): UrlGenerator => new UrlGenerator($router));
        $container->singleton(FeatureScope::class, static fn (): FeatureScope => new FeatureScope(Flags::of()));

        (new ViewModule())->register($container, new AppContext($this->templates->dir(), 'dev', new Config($config), Flags::of()));

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function renderer(array $config): ViewRenderer
    {
        $renderer = $this->container($config)->get(ViewRenderer::class);
        self::assertInstanceOf(ViewRenderer::class, $renderer);

        return $renderer;
    }

    public function testANamespaceSearchesItsDirectoriesInOrder(): void
    {
        $renderer = $this->renderer(['view.namespaces' => ['paper' => ['themes/paper', 'views'], 'shared' => 'views']]);

        self::assertSame('paper card', $renderer->renderToString('@paper/card'));
        self::assertSame('shared layout', $renderer->renderToString('@paper/layout'), 'What the theme lacks comes from the next directory.');
        self::assertSame('shared card', $renderer->renderToString('@shared/card'), 'One directory may be a string.');
        self::assertSame('shared card', $renderer->renderToString('card'), 'The main directory is untouched.');
    }

    public function testANamespaceDirectoryThatDoesNotExistFailsTheBootNamingItsKey(): void
    {
        try {
            $this->container(['view.namespaces' => ['paper' => ['themes/paper', 'themes/nope']]]);
            self::fail('A namespace directory that does not exist should fail the boot.');
        } catch (ViewDirMissing $problem) {
            self::assertSame('view.namespaces.paper', $problem->context['config_key']);
            self::assertStringContainsString('themes/nope', $problem->getMessage());
            self::assertStringContainsString("'namespaces'", $problem->fix);
        }
    }

    public function testNamespacesOfTheWrongShapeAreInvalidConfig(): void
    {
        foreach ([['Paper Theme' => 'views'], ['paper' => 42], ['paper' => []], ['paper' => ['views', 7]]] as $namespaces) {
            try {
                $this->container(['view.namespaces' => $namespaces]);
                self::fail('Accepted ' . json_encode($namespaces));
            } catch (InvalidConfig $problem) {
                self::assertStringContainsString("'view.namespaces", $problem->getMessage());
            }
        }
    }

    public function testAnExtensionListedInConfigIsInstalledBeforeAnythingRenders(): void
    {
        $container = $this->container(['view.extensions' => ['app.whisper']]);
        $container->value('app.whisper', new class extends AbstractExtension {
            public function getFilters(): array
            {
                return [new TwigFilter('whisper', static fn (string $text): string => strtolower($text))];
            }
        });

        $renderer = $container->get(ViewRenderer::class);

        self::assertInstanceOf(ViewRenderer::class, $renderer);
        self::assertTrue($renderer->environment()->hasExtension($container->get('app.whisper')::class));
        self::assertSame('hello there', $renderer->renderToString('whisper'));
    }

    public function testAnExtensionIdWhoseServiceIsNotAnExtensionIsInvalidConfig(): void
    {
        $container = $this->container(['view.extensions' => ['app.not_an_extension']]);
        $container->value('app.not_an_extension', new \stdClass());

        $this->expectException(InvalidConfig::class);
        $container->get(ViewRenderer::class);
    }

    public function testExtensionsOfTheWrongShapeAreInvalidConfigNamingTheMistake(): void
    {
        $cases = [
            'a map' => [['shout' => 'app.whisper'], "got a map, with the key 'shout'"],
            'a list with a gap' => [[1 => 'app.whisper'], 'got the key 1 where 0 belongs'],
            'not an id' => [[42], 'got int'],
            'an id twice' => [['app.whisper', 'app.whisper'], "got 'app.whisper' twice"],
            'an empty id' => [[''], 'got string'],
        ];

        foreach ($cases as $case => [$extensions, $got]) {
            try {
                $this->container(['view.extensions' => $extensions]);
                self::fail("Accepted {$case}.");
            } catch (InvalidConfig $problem) {
                self::assertStringContainsString("'view.extensions'", $problem->getMessage(), $case);
                self::assertStringContainsString($got, $problem->getMessage(), $case);
            }
        }
    }

    public function testAnExtensionIdNothingRegistersNamesConfigViewPhp(): void
    {
        // Lava Notes R3-B14: the problem named the pack's own factory as where
        // the id was asked for, and its fix never mentioned view.extensions.
        $file = $this->templates->dir() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'view.php';
        $cases = [
            'no such class' => ['App\View\Missing', 'add its `use` import to config/view.php'],
            'a class nothing registered' => [StringLoaderExtension::class, 'Register it in app/Services.php'],
        ];

        foreach ($cases as $case => [$id, $fix]) {
            try {
                $this->container(['view.extensions' => [$id]])->get(ViewRenderer::class);
                self::fail("Accepted {$case}.");
            } catch (ServiceNotRegistered $problem) {
                self::assertSame('service_not_registered', $problem->code(), $case);
                self::assertStringContainsString("Service '{$id}' is not registered", $problem->getMessage(), $case);
                self::assertSame($file, $problem->context['referenced_from'], $case);
                self::assertSame($file, $problem->source?->file, $case);
                self::assertStringContainsString($fix, $problem->fix, $case);
                self::assertStringContainsString("remove it from 'extensions' in config/view.php", $problem->fix, $case);
            }
        }
    }

    public function testANamespaceConfigDoesNotDeclareIsSentToConfigViewPhpWithTheOnesItDoes(): void
    {
        // Lava Notes R3-B14: the fix was the addPath() call from before
        // view.namespaces existed, and named no namespace that did.
        try {
            $this->renderer(['view.namespaces' => ['paper' => ['themes/paper', 'views'], 'shared' => 'views']])->renderToString('@lava/home');
            self::fail('A namespace nothing declares should raise template_not_found.');
        } catch (TemplateNotFound $problem) {
            self::assertStringContainsString("no directory is registered for the Twig namespace '@lava'. Declared: @paper, @shared.", $problem->getMessage());
            self::assertStringContainsString("config/view.php under 'namespaces'", $problem->fix);
            self::assertStringContainsString("'namespaces' => ['lava' => 'path/to/templates']", $problem->fix);
            self::assertStringNotContainsString('addPath', $problem->fix);
            self::assertSame(['paper', 'shared'], $problem->context['declared']);
        }
    }
}
