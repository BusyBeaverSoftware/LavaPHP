<?php

declare(strict_types=1);

namespace Lava\Events\Tests\Boot;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Testing\TestApp;
use Lava\Events\EventDispatcher;
use Lava\Events\Tests\Support\NeedsTwo;
use Lava\Events\Tests\Support\NotInvokable;
use Lava\Events\Tests\Support\RecordsShipment;
use Lava\Events\Tests\Support\Shipped;
use Lava\Events\Tests\Support\TakesAnything;
use Lava\Events\Tests\Support\TakesNothing;
use Lava\Events\Tests\Support\TakesStdClass;
use Lava\Events\Tests\Support\TakesString;
use Lava\Events\Tests\Support\TakesTrackable;
use Lava\Events\Tests\Support\TakesUnion;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/Fixtures.php';

/**
 * Every listener that could not run is a boot problem: the pack checks the
 * whole registry while boot builds the provider, not when an event first fires.
 */
final class EventsModuleBootTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (['Modules.php', 'Services.php', 'Listeners.php'] as $file) {
                @unlink("{$dir}/app/{$file}");
            }
            @rmdir("{$dir}/app");
            @rmdir($dir);
        }
    }

    /**
     * An app with the events pack, the given listener classes registered, and
     * `$listeners` as its app/Listeners.php.
     *
     * @param array<string, list<string>> $listeners
     * @param list<class-string> $registered
     */
    private function boot(array $listeners, array $registered): App|BootFailure
    {
        $dir = sys_get_temp_dir() . '/lava-events-boot-' . bin2hex(random_bytes(6));
        mkdir($dir . '/app', 0o777, true);
        $this->dirs[] = $dir;

        file_put_contents("{$dir}/app/Modules.php", "<?php\nreturn [\\Lava\\Core\\Modules\\ModuleRef::of(\\Lava\\Events\\EventsModule::class, package: 'lavaphp/events', feature: 'events')];\n");
        $services = '';
        foreach ($registered as $class) {
            $services .= "    \$c->singleton('{$class}', static fn () => new \\{$class}());\n";
        }
        file_put_contents("{$dir}/app/Services.php", "<?php\nreturn function (\\Lava\\Core\\Container\\Container \$c, \\Lava\\Core\\Boot\\AppContext \$ctx): void {\n{$services}};\n");
        file_put_contents("{$dir}/app/Listeners.php", "<?php\nreturn " . var_export($listeners, true) . ";\n");

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
}
