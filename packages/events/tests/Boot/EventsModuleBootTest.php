<?php

declare(strict_types=1);

namespace Lava\Events\Tests\Boot;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Testing\TestApp;
use Lava\Events\EventDispatcher;
use Lava\Events\Tests\Support\AuditsShipments;
use Lava\Events\Tests\Support\NeedsTwo;
use Lava\Events\Tests\Support\NotInvokable;
use Lava\Events\Tests\Support\RecordsShipment;
use Lava\Events\Tests\Support\RendersShipment;
use Lava\Events\Tests\Support\Shipped;
use Lava\Events\Tests\Support\ShippingExtension;
use Lava\Events\Tests\Support\ShipsOrders;
use Lava\Events\Tests\Support\TakesAnything;
use Lava\Events\Tests\Support\TakesNothing;
use Lava\Events\Tests\Support\TakesStdClass;
use Lava\Events\Tests\Support\TakesString;
use Lava\Events\Tests\Support\TakesTrackable;
use Lava\Events\Tests\Support\TakesUnion;
use Lava\View\ViewModule;
use Lava\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/Fixtures.php';

/**
 * Every listener that could not run is a boot problem: the pack checks the
 * whole registry while boot builds the provider, not when an event first fires.
 */
final class EventsModuleBootTest extends TestCase
{
    private const EVENTS_MODULE = "\\Lava\\Core\\Modules\\ModuleRef::of(\\Lava\\Events\\EventsModule::class, package: 'lavaphp/events', feature: 'events')";

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                $entry instanceof \SplFileInfo && $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink((string) $entry);
            }
            @rmdir($dir);
        }
    }

    /**
     * An app with the events pack, the given listener classes registered, and
     * `$listeners` as its app/Listeners.php.
     *
     * @param array<string, list<string>> $listeners
     * @param list<class-string> $registered registered as `new $class()`
     * @param array<class-string, list<string>> $wired registered as `new $class($c->get($dep), …)`
     * @param array<string, string> $files more files, path relative to the app => contents
     * @param list<string> $modules more app/Modules.php entries, as PHP expressions
     */
    private function boot(array $listeners, array $registered, array $wired = [], array $files = [], array $modules = []): App|BootFailure
    {
        $dir = sys_get_temp_dir() . '/lava-events-boot-' . bin2hex(random_bytes(6));
        mkdir($dir . '/app', 0o777, true);
        $this->dirs[] = $dir;

        file_put_contents("{$dir}/app/Modules.php", "<?php\nreturn [" . implode(', ', [self::EVENTS_MODULE, ...$modules]) . "];\n");
        $services = '';
        foreach ($registered as $class) {
            $services .= "    \$c->singleton('{$class}', static fn () => new \\{$class}());\n";
        }
        foreach ($wired as $class => $dependencies) {
            $arguments = implode(', ', array_map(static fn (string $id): string => "\$c->get('{$id}')", $dependencies));
            $services .= "    \$c->singleton('{$class}', static fn (\\Lava\\Core\\Container\\Container \$c) => new \\{$class}({$arguments}));\n";
        }
        file_put_contents("{$dir}/app/Services.php", "<?php\nreturn function (\\Lava\\Core\\Container\\Container \$c, \\Lava\\Core\\Boot\\AppContext \$ctx): void {\n{$services}};\n");
        file_put_contents("{$dir}/app/Listeners.php", "<?php\nreturn " . var_export($listeners, true) . ";\n");
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname("{$dir}/{$path}"))) {
                mkdir(dirname("{$dir}/{$path}"), 0o777, true);
            }
            file_put_contents("{$dir}/{$path}", $contents);
        }

        return TestApp::boot($dir);
    }

    public function testListenersThatCanTakeTheirEventBootAndRun(): void
    {
        $app = $this->boot(
            [Shipped::class => [RecordsShipment::class, TakesTrackable::class, TakesAnything::class]],
            [RecordsShipment::class, TakesTrackable::class, TakesAnything::class],
        );
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        $dispatcher = $app->container->get(EventDispatcher::class);
        self::assertInstanceOf(EventDispatcher::class, $dispatcher);
        $dispatcher->dispatch(new Shipped('A1'));

        $recorder = $app->container->get(RecordsShipment::class);
        self::assertInstanceOf(RecordsShipment::class, $recorder);
        self::assertSame(['A1'], $recorder->seen);
    }

    public function testEachListenerThatCannotRunFailsTheBoot(): void
    {
        $cases = [
            'an event class that does not exist' => ['App\Nowhere\Delivered', RecordsShipment::class, 'bad_listener', 'no class or interface has that name'],
            'a listener nobody registered' => [Shipped::class, null, 'service_not_registered', 'RecordsShipment'],
            'no __invoke()' => [Shipped::class, NotInvokable::class, 'bad_listener', 'has no __invoke() method'],
            'no parameter' => [Shipped::class, TakesNothing::class, 'bad_listener', '__invoke() takes no parameter'],
            'a builtin type' => [Shipped::class, TakesString::class, 'bad_listener', 'its parameter is typed string'],
            'another class' => [Shipped::class, TakesStdClass::class, 'bad_listener', 'its parameter is typed stdClass'],
            'a union' => [Shipped::class, TakesUnion::class, 'bad_listener', 'not one class'],
            'a second required parameter' => [Shipped::class, NeedsTwo::class, 'bad_listener', 'also requires $count'],
        ];

        foreach ($cases as $case => [$event, $listener, $code, $message]) {
            $class = $listener ?? RecordsShipment::class;
            $failure = $this->boot([$event => [$class]], $listener === null ? [] : [$class]);

            self::assertInstanceOf(BootFailure::class, $failure, $case);
            $problems = $failure->problems->problems();
            self::assertSame([$code], array_map(static fn ($problem): string => $problem->code(), $problems), $case);
            self::assertStringContainsString($message, $problems[0]->getMessage(), $case);
        }
    }

    public function testAListenersFileThatRaisesAnErrorFailsTheBootAtItsLine(): void
    {
        // Lava Notes R3-B8: reported as "Module class Lava\Events\EventsModule
        // cannot be instantiated", with the constructor fix, at app/Modules.php.
        $failure = $this->boot([], [], [], ['app/Listeners.php' => "<?php\nreturn [str_repeat('x', 'y') => []];\n"]);

        self::assertInstanceOf(BootFailure::class, $failure);
        $problems = $failure->problems->problems();
        self::assertSame(['invalid_listeners_file'], array_map(static fn ($problem): string => $problem->code(), $problems));
        self::assertStringContainsString('must be of type int, string given', $problems[0]->getMessage());
        self::assertStringEndsWith('/app/Listeners.php', (string) $problems[0]->source?->file);
        self::assertSame(2, $problems[0]->source?->line);
    }

    public function testAListenerMayDependOnTheServiceThatDispatchesItsEvent(): void
    {
        // Lava Notes R3-B2: the dispatcher took the provider when it was built,
        // and building the provider builds every listener, so this app closed
        // AuditsShipments -> ShipsOrders -> EventDispatcher -> ListenerProvider
        // -> AuditsShipments, a cycle it never wrote.
        $app = $this->boot([Shipped::class => [AuditsShipments::class]], [], [
            ShipsOrders::class => [EventDispatcher::class],
            AuditsShipments::class => [ShipsOrders::class],
        ]);
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        $shipper = $app->container->get(ShipsOrders::class);
        self::assertInstanceOf(ShipsOrders::class, $shipper);
        $shipper->ship('A1');
        $shipper->ship('A2');

        $audit = $app->container->get(AuditsShipments::class);
        self::assertInstanceOf(AuditsShipments::class, $audit);
        self::assertSame(['A1', 'A2'], $audit->seen);
        self::assertSame($shipper, $audit->shipper, 'The listener boot built is the one dispatch reaches.');
    }

    public function testATwigExtensionMayDispatchToAListenerThatRenders(): void
    {
        if (!class_exists(ViewModule::class)) {
            self::markTestSkipped('Needs lavaphp/view, which the monorepo installs and lavaphp/events does not require.');
        }
        require_once dirname(__DIR__) . '/Support/ViewFixtures.php';

        // The same cycle through the renderer: ViewRenderer installs
        // ShippingExtension, which takes the dispatcher, whose provider built
        // RendersShipment, which takes ViewRenderer.
        $app = $this->boot(
            [Shipped::class => [RendersShipment::class]],
            [],
            [
                ShippingExtension::class => [EventDispatcher::class],
                RendersShipment::class => [ViewRenderer::class],
            ],
            [
                'config/view.php' => "<?php\nreturn ['cache' => '', 'extensions' => ['" . ShippingExtension::class . "']];\n",
                'views/page.twig' => "{{ ship('B7') }}",
                'views/note.twig' => 'note for {{ order }}',
            ],
            ["\\Lava\\Core\\Modules\\ModuleRef::of(\\Lava\\View\\ViewModule::class, package: 'lavaphp/view', feature: 'views')"],
        );
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        $views = $app->container->get(ViewRenderer::class);
        self::assertInstanceOf(ViewRenderer::class, $views);
        self::assertSame('shipped B7', $views->renderToString('page.twig'));

        $listener = $app->container->get(RendersShipment::class);
        self::assertInstanceOf(RendersShipment::class, $listener);
        self::assertSame(['note for B7'], $listener->rendered);
    }
}
