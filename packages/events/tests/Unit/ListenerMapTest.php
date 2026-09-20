<?php

declare(strict_types=1);

namespace Lava\Events\Tests\Unit;

use Lava\Events\ListenerMap;
use Lava\Events\Problem\InvalidListenersFile;
use Lava\Events\Tests\Support\Shipped;
use Lava\Events\Tests\Support\Trackable;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/Fixtures.php';

/** `app/Listeners.php`: its shape, and which listeners an event reaches. */
final class ListenerMapTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            @unlink($dir . '/app/Listeners.php');
            @rmdir($dir . '/app');
            @rmdir($dir);
        }
    }

    private function appWith(?string $returned): string
    {
        $dir = sys_get_temp_dir() . '/lava-events-map-' . bin2hex(random_bytes(6));
        mkdir($dir . '/app', 0o777, true);
        if ($returned !== null) {
            file_put_contents($dir . '/app/Listeners.php', "<?php\nreturn {$returned};\n");
        }

        return $this->dirs[] = $dir;
    }

    public function testAnAppWithoutTheFileHasNoListeners(): void
    {
        $map = ListenerMap::load($this->appWith(null));

        self::assertSame([], $map->all());
        self::assertNull($map->file);
        self::assertSame([], $map->for(new Shipped('A1')));
    }

    public function testAnEventReachesEveryEntryItIsAnInstanceOfInFileOrderAndEachListenerOnce(): void
    {
        $map = new ListenerMap([
            Shipped::class => ['app.record', 'app.email'],
            Trackable::class => ['app.track', 'app.record'],
            \stdClass::class => ['app.never'],
        ]);

        self::assertSame(['app.record', 'app.email', 'app.track'], $map->for(new Shipped('A1')));
        self::assertSame([], $map->for(new \ArrayObject()));
    }

    public function testAPhaseRunsBeforeOrAfterTheDefaultOnesAcrossEveryEntry(): void
    {
        // The point of a phase over moving a line: `app.audit` is named under
        // the class and still runs after `app.track`, which another entry names.
        $map = ListenerMap::load($this->appWith(
            '[' . var_export(Shipped::class, true) . ' => [\\Lava\\Events\\Phase::last(\'app.audit\'), \'app.record\'],'
            . var_export(Trackable::class, true) . ' => [\'app.track\', \\Lava\\Events\\Phase::first(\'app.quota\')]]',
        ));

        self::assertSame(['app.quota', 'app.record', 'app.track', 'app.audit'], $map->for(new Shipped('A1')));
        self::assertSame(['app.quota', 'app.track'], $map->forClass(Trackable::class));
        self::assertSame('first', $map->phaseOf('app.quota'));
        self::assertSame('last', $map->phaseOf('app.audit'));
        self::assertSame('default', $map->phaseOf('app.record'), 'A bare id is the default phase.');
        self::assertSame('default', $map->phaseOf('app.nobody'), 'And so is an id the file never names.');

        // File order still decides inside a phase, and `all()` still reports
        // the file as written, wrappers unwrapped.
        self::assertSame([Shipped::class => ['app.audit', 'app.record'], Trackable::class => ['app.track', 'app.quota']], $map->all());
    }

    public function testOneListenerGivenTwoPhasesIsAConflictAndABareIdIsTheDefaultPhase(): void
    {
        $cases = [
            'two entries disagree' => '[' . var_export(Shipped::class, true) . ' => [\\Lava\\Events\\Phase::last(\'app.audit\')],'
                . var_export(Trackable::class, true) . ' => [\'app.audit\']]',
            'one entry says both' => '[' . var_export(Shipped::class, true) . ' => [\\Lava\\Events\\Phase::first(\'app.audit\'), \\Lava\\Events\\Phase::last(\'app.audit\')]]',
            'a phase beside a bare id' => '[' . var_export(Shipped::class, true) . ' => [\\Lava\\Events\\Phase::first(\'app.audit\'), \'app.audit\']]',
        ];

        foreach ($cases as $case => $returned) {
            $conflicts = ListenerMap::load($this->appWith($returned))->orderConflicts();
            self::assertCount(1, $conflicts, $case);
            self::assertSame('app.audit', $conflicts[0]['listener'], $case);
            self::assertCount(2, $conflicts[0]['byPhase'], $case);
        }

        // Named twice with the SAME phase is not a conflict: it still runs once.
        $agrees = '[' . var_export(Shipped::class, true) . ' => [\\Lava\\Events\\Phase::last(\'app.audit\')],'
            . var_export(Trackable::class, true) . ' => [\\Lava\\Events\\Phase::last(\'app.audit\')]]';
        $map = ListenerMap::load($this->appWith($agrees));
        self::assertSame([], $map->orderConflicts());
        self::assertSame(['app.audit'], $map->for(new Shipped('A1')));

        // And `Phase::default()` says what a bare id already says.
        $explicit = ListenerMap::load($this->appWith(
            '[' . var_export(Shipped::class, true) . ' => [\\Lava\\Events\\Phase::default(\'app.record\')],'
            . var_export(Trackable::class, true) . ' => [\'app.record\']]',
        ));
        self::assertSame([], $explicit->orderConflicts());
        self::assertSame('default', $explicit->phaseOf('app.record'));
    }

    public function testOrderedIsWhatEachEntryRunsWithItsPhase(): void
    {
        $map = ListenerMap::load($this->appWith(
            '[' . var_export(Shipped::class, true) . ' => [\'app.record\', \\Lava\\Events\\Phase::last(\'app.audit\')],'
            . var_export(Trackable::class, true) . ' => [\'app.track\']]',
        ));

        // Shipped is Trackable, so its entry runs `app.track` too — which is why
        // this is not `all()` with labels bolted on.
        self::assertSame([
            Shipped::class => [
                ['listener' => 'app.record', 'phase' => 'default'],
                ['listener' => 'app.track', 'phase' => 'default'],
                ['listener' => 'app.audit', 'phase' => 'last'],
            ],
            Trackable::class => [
                ['listener' => 'app.track', 'phase' => 'default'],
            ],
        ], $map->ordered());
    }

    public function testAMisspelledPhaseIsTheFilesOwnErrorAtItsLine(): void
    {
        // The wrapper is why a phase cannot be misspelled into silence: an
        // undefined method is an \Error inside the required file.
        try {
            ListenerMap::load($this->appWith('[' . var_export(Shipped::class, true) . ' => [\\Lava\\Events\\Phase::frist(\'app.record\')]]'));
            self::fail('Accepted Phase::frist().');
        } catch (InvalidListenersFile $problem) {
            self::assertSame('invalid_listeners_file', $problem->code());
            self::assertSame(\Error::class, $problem->context['error']);
            self::assertSame(2, $problem->source?->line);
        }
    }

    public function testOneListenerMayBeGivenAsAString(): void
    {
        $map = ListenerMap::load($this->appWith(var_export([Shipped::class => 'app.record'], true)));

        self::assertSame([Shipped::class => ['app.record']], $map->all());
        self::assertStringEndsWith('/app/Listeners.php', (string) $map->file);
    }

    public function testAFileOfTheWrongShapeIsInvalidListenersFile(): void
    {
        $cases = [
            "'not a map'" => 'returned string instead of a map',
            "[42 => 'app.x']" => 'has the key 42, which is not an event class',
            "['App\\\\Done' => []]" => 'gives App\Done array instead',
            "['App\\\\Done' => ['app.x', 7]]" => 'gives App\Done a list holding int instead',
        ];

        foreach ($cases as $returned => $message) {
            try {
                ListenerMap::load($this->appWith($returned));
                self::fail("Accepted {$returned}.");
            } catch (InvalidListenersFile $problem) {
                self::assertSame('invalid_listeners_file', $problem->code());
                self::assertStringContainsString($message, $problem->getMessage(), $returned);
            }
        }
    }

    public function testAFileThatCannotBeReadIsInvalidListenersFileAtTheErrorsLine(): void
    {
        // Lava Notes R3-B8: an \Error from the file reached boot as the module
        // failing to construct, sourced at app/Modules.php.
        $cases = [
            'a parse error' => ['[', \ParseError::class, 2, 'Fix line 2 of the file'],
            'a TypeError in the file' => ["str_repeat('x', 'y')", \TypeError::class, 2, 'Fix line 2 of the file'],
            'a TypeError the file reached elsewhere' => ['new \\' . Shipped::class . '([])', \TypeError::class, 1, 'Support/Fixtures.php:'],
        ];

        foreach ($cases as $case => [$returned, $error, $line, $fix]) {
            $dir = $this->appWith($returned);
            try {
                ListenerMap::load($dir);
                self::fail("Read a file with {$case}.");
            } catch (InvalidListenersFile $problem) {
                self::assertSame('invalid_listeners_file', $problem->code(), $case);
                self::assertStringStartsWith("The listeners file {$dir}/app/Listeners.php could not be read: ", $problem->getMessage(), $case);
                self::assertStringContainsString($fix, $problem->fix, $case);
                self::assertSame($error, $problem->context['error'], $case);
                self::assertInstanceOf($error, $problem->getPrevious(), $case);
                self::assertSame("{$dir}/app/Listeners.php", $problem->source?->file, $case);
                self::assertSame($line, $problem->source->line, $case);
            }
        }
    }
}
