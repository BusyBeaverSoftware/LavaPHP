<?php

declare(strict_types=1);

namespace Lava\View\Tests\Unit;

use Lava\Core\Boot\AppContext;
use Lava\Core\Config\Config;
use Lava\Core\Container\Container;
use Lava\Core\Features\FeatureScope;
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
use Twig\Extension\AbstractExtension;
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

    public function testExtensionsOfTheWrongShapeAreInvalidConfig(): void
    {
        foreach ([['a' => 'app.whisper'], [42], ['app.whisper', 'app.whisper'], ['']] as $extensions) {
            try {
                $this->container(['view.extensions' => $extensions]);
                self::fail('Accepted ' . json_encode($extensions));
            } catch (InvalidConfig $problem) {
                self::assertStringContainsString("'view.extensions'", $problem->getMessage());
            }
        }
    }
}
