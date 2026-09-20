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
use Lava\Events\Tests\Support\Trackable;
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

    public function testAListenerRegisteredWithFactoryIsRefusedAtBoot(): void
    {
        // A listener is built once and held, so `factory()` promises what the
        // pack cannot give: the mistake is silent at runtime (Lava Notes, R3-B10).
        $services = "<?php\nreturn function (\\Lava\\Core\\Container\\Container \$c, \\Lava\\Core\\Boot\\AppContext \$ctx): void {\n"
            . "    \$c->factory('" . RecordsShipment::class . "', static fn () => new \\" . RecordsShipment::class . "());\n"
            . "};\n";

        $boot = $this->boot([Shipped::class => [RecordsShipment::class]], [], [], ['app/Services.php' => $services]);

        self::assertInstanceOf(BootFailure::class, $boot);
        $problems = $boot->problems->problems();
        self::assertSame(['factory_listener'], array_map(static fn ($p): string => $p->code(), $problems));
        $problem = $problems[0];
        self::assertStringContainsString('is registered with factory()', $problem->getMessage());
        self::assertStringContainsString('Register it with singleton() in ', $problem->fix);
        self::assertSame(RecordsShipment::class, $problem->context['listener']);
        self::assertSame('factory', $problem->context['kind']);
        self::assertStringContainsString('app/Services.php:', (string) $problem->context['registered_at']);
        self::assertStringEndsWith('app/Listeners.php', (string) $problem->source?->file);
        self::assertSame(1, $problem->source?->line);
    }

    public function testAnAliasToAFactoryIsRefusedUnderTheNameTheFileLists(): void
    {
        // describe() follows the alias, so the kind is seen; the problem names
        // the id app/Listeners.php writes, which is the line a reader edits.
        $services = "<?php\nreturn function (\\Lava\\Core\\Container\\Container \$c, \\Lava\\Core\\Boot\\AppContext \$ctx): void {\n"
            . "    \$c->factory('" . RecordsShipment::class . "', static fn () => new \\" . RecordsShipment::class . "());\n"
            . "    \$c->alias('App\\\\Listeners\\\\ShipmentLog', '" . RecordsShipment::class . "');\n"
            . "};\n";

        $boot = $this->boot([Shipped::class => ['App\\Listeners\\ShipmentLog']], [], [], ['app/Services.php' => $services]);

        self::assertInstanceOf(BootFailure::class, $boot);
        $problem = $boot->problems->problems()[0];
        self::assertSame('factory_listener', $problem->code());
        self::assertSame('App\\Listeners\\ShipmentLog', $problem->context['listener']);
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
            // Every one at the listeners file, absolute, so it can be opened
            // directly; the unregistered listener had no source (R3-B9).
            self::assertSame(end($this->dirs) . '/app/Listeners.php', $problems[0]->source?->file, $case);
            self::assertSame(1, $problems[0]->source->line, $case);
        }

        $unregistered = $this->boot([Shipped::class => [RecordsShipment::class]], []);
        self::assertInstanceOf(BootFailure::class, $unregistered);
        self::assertSame(
            ['id' => RecordsShipment::class, 'referenced_from' => 'app/Listeners.php'],
            $unregistered->problems->problems()[0]->context,
            'The context is what ServiceNotRegistered::of() gives; only the source is added.',
        );
    }

    public function testEveryListenerMistakeInTheFileIsReportedInOneBoot(): void
    {
        // Lava Notes R3-B9: the provider threw on the first listener it could
        // not use, so a registry with three mistakes took three boots to learn
        // about — while the rest of the sweep reports everything it finds.
        $failure = $this->boot(
            [Shipped::class => [TakesString::class, RecordsShipment::class, NotInvokable::class]],
            [TakesString::class, NotInvokable::class],
        );

        self::assertInstanceOf(BootFailure::class, $failure);
        $problems = $failure->problems->problems();
        self::assertSame(
            ['bad_listener', 'service_not_registered', 'bad_listener'],
            array_map(static fn ($problem): string => $problem->code(), $problems),
            'in file order, which is the order the reader fixes them in',
        );
        self::assertStringContainsString('its parameter is typed string', $problems[0]->getMessage());
        self::assertStringContainsString('RecordsShipment', $problems[1]->getMessage());
        self::assertStringContainsString('has no __invoke() method', $problems[2]->getMessage());

        foreach ($problems as $problem) {
            self::assertSame(end($this->dirs) . '/app/Listeners.php', $problem->source?->file);
        }
    }

    public function testEveryUnknownEventKeyIsReportedInOneBoot(): void
    {
        $failure = $this->boot(
            ['App\Nowhere\Delivered' => [RecordsShipment::class], 'App\Nowhere\Returned' => [RecordsShipment::class]],
            [RecordsShipment::class],
        );

        self::assertInstanceOf(BootFailure::class, $failure);
        $problems = $failure->problems->problems();
        self::assertSame(
            ['bad_listener', 'bad_listener'],
            array_map(static fn ($problem): string => $problem->code(), $problems),
        );
        self::assertStringContainsString("'App\\Nowhere\\Delivered'", $problems[0]->getMessage());
        self::assertStringContainsString("'App\\Nowhere\\Returned'", $problems[1]->getMessage());
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

    /** An `app/Listeners.php` written out, so it can hold `Phase::…()` calls `var_export()` cannot. */
    private static function listenersFile(string $returned): string
    {
        return "<?php\nuse Lava\\Events\\Phase;\nreturn {$returned};\n";
    }

    public function testAListenerGivenTwoPhasesIsRefusedAtBoot(): void
    {
        $shipped = Shipped::class;
        $trackable = Trackable::class;
        $records = RecordsShipment::class;
        $cases = [
            'two entries disagree' => "['{$shipped}' => [Phase::last('{$records}')], '{$trackable}' => ['{$records}']]",
            'one entry says both' => "['{$shipped}' => [Phase::first('{$records}'), Phase::last('{$records}')]]",
            'a phase beside a bare id' => "['{$shipped}' => [Phase::first('{$records}'), '{$records}']]",
        ];

        foreach ($cases as $case => $returned) {
            $boot = $this->boot([], [RecordsShipment::class], [], ['app/Listeners.php' => self::listenersFile($returned)]);

            self::assertInstanceOf(BootFailure::class, $boot, $case);
            $problems = $boot->problems->problems();
            self::assertSame(['listener_order_conflict'], array_map(static fn ($p): string => $p->code(), $problems), $case);
            self::assertSame($records, $problems[0]->context['listener'], $case);
            self::assertStringContainsString("Listener '{$records}' is ", $problems[0]->getMessage(), $case);
            self::assertStringContainsString('it runs once', $problems[0]->getMessage(), $case);
            self::assertStringContainsString('Give it one phase', $problems[0]->fix, $case);
            self::assertStringEndsWith('app/Listeners.php', (string) $problems[0]->source?->file, $case);
        }
    }

    public function testAConflictArrivesWithEverythingElseTheFileGotWrong(): void
    {
        // Both are facts about the file alone, so one boot names both.
        $returned = "['App\\\\Nowhere' => ['" . RecordsShipment::class . "'],"
            . " '" . Shipped::class . "' => [Phase::last('" . RecordsShipment::class . "')],"
            . " '" . Trackable::class . "' => ['" . RecordsShipment::class . "']]";

        $boot = $this->boot([], [RecordsShipment::class], [], ['app/Listeners.php' => self::listenersFile($returned)]);

        self::assertInstanceOf(BootFailure::class, $boot);
        self::assertSame(
            ['bad_listener', 'listener_order_conflict'],
            array_map(static fn ($p): string => $p->code(), $boot->problems->problems()),
        );
    }

    public function testAPhaseDoesNotExcuseAListenerFromAnyOtherCheck(): void
    {
        // The wrapper is unwrapped before every existing check, so a phase
        // never turns a refusal into silence.
        $unregistered = $this->boot([], [], [], [
            'app/Listeners.php' => self::listenersFile("['" . Shipped::class . "' => [Phase::last('App\\\\Nobody')]]"),
        ]);
        self::assertInstanceOf(BootFailure::class, $unregistered);
        self::assertSame(
            ['service_not_registered'],
            array_map(static fn ($p): string => $p->code(), $unregistered->problems->problems()),
        );

        $services = "<?php\nreturn function (\\Lava\\Core\\Container\\Container \$c, \\Lava\\Core\\Boot\\AppContext \$ctx): void {\n"
            . "    \$c->factory('" . RecordsShipment::class . "', static fn () => new \\" . RecordsShipment::class . "());\n"
            . "};\n";
        $factory = $this->boot([], [], [], [
            'app/Services.php' => $services,
            'app/Listeners.php' => self::listenersFile("['" . Shipped::class . "' => [Phase::first('" . RecordsShipment::class . "')]]"),
        ]);
        self::assertInstanceOf(BootFailure::class, $factory);
        self::assertSame(
            ['factory_listener'],
            array_map(static fn ($p): string => $p->code(), $factory->problems->problems()),
        );
    }

    public function testPhasesOrderTheListenersDispatchReaches(): void
    {
        $returned = "['" . Shipped::class . "' => [Phase::last('" . TakesAnything::class . "'), '" . RecordsShipment::class . "'],"
            . " '" . Trackable::class . "' => [Phase::first('" . TakesTrackable::class . "')]]";

        $app = $this->boot(
            [],
            [RecordsShipment::class, TakesTrackable::class, TakesAnything::class],
            [],
            ['app/Listeners.php' => self::listenersFile($returned)],
        );
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        $map = $app->container->get(\Lava\Events\ListenerMap::class);
        self::assertInstanceOf(\Lava\Events\ListenerMap::class, $map);
        self::assertSame(
            [TakesTrackable::class, RecordsShipment::class, TakesAnything::class],
            $map->for(new Shipped('A1')),
            'first, then the default phase in file order, then last — across both entries.',
        );
    }
}
