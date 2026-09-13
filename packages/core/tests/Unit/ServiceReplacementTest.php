<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use App\RecordingLogger;
use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Clock\SystemClock;
use Lava\Core\Container\Container;
use Lava\Core\Log\LineLogger;
use Lava\Core\Problem\BadReplacement;
use Lava\Core\Testing\FrozenClock;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Two ways a service ends up being something other than core's: the app
 * registers a standard id first (the defaults), or a test replaces an id for
 * one boot (`TestApp::boot(replace:)`).
 *
 * Both came out of the same outside build. Its test doubles had to live in
 * app/Services.php behind `LAVA_ENV=test`, because nothing else could swap a
 * service — so a production server started with the wrong environment would
 * have frozen its own clock. And it could not register a logger of its own,
 * because core had already taken `LoggerInterface`.
 */
final class ServiceReplacementTest extends TestCase
{
    public function testCoreFillsTheLoggerAndTheClockWhenNothingElseDid(): void
    {
        $app = self::boot('ok-app');

        self::assertInstanceOf(LineLogger::class, $app->container->get(LoggerInterface::class));
        self::assertInstanceOf(SystemClock::class, $app->container->get(ClockInterface::class));
    }

    public function testAnAppThatRegistersTheLoggerItselfGetsItsOwnAndNoDuplicate(): void
    {
        $app = self::boot('handler-app');

        self::assertTrue($app->problems->isEmpty());
        self::assertInstanceOf(RecordingLogger::class, $app->container->get(LoggerInterface::class));
        // The id is registered once, by the app — the map and `lava services`
        // name the app's wiring file, not core's.
        self::assertSame('Services.php', basename($app->container->describe(LoggerInterface::class)->file));
    }

    public function testAReplacedClockIsTheOneEveryHandlerReads(): void
    {
        $clock = new FrozenClock('2026-09-13 09:00:00');
        $client = new TestClient(self::boot('handler-app', [ClockInterface::class => $clock]));

        self::assertSame(['now' => '2026-09-13 09:00:00'], $client->get('/now')->json());

        // The test keeps the object, so moving it moves the app's time.
        $clock->advance('PT45M');
        self::assertSame(['now' => '2026-09-13 09:45:00'], $client->get('/now')->json());
    }

    public function testAReplacementReachesWhatTheAppDoesWithTheId(): void
    {
        TestApp::autoloadFixture('handler-app');
        $replacement = new RecordingLogger();
        $app = TestApp::bootFixture('handler-app', ['LAVA_ENV' => 'prod'], [LoggerInterface::class => $replacement]);
        self::assertInstanceOf(App::class, $app);

        (new TestClient($app))->get('/boom');

        self::assertCount(1, $replacement->entries);
    }

    public function testReplacingAnIdNothingRegistersIsABootProblem(): void
    {
        $boot = TestApp::bootFixture('handler-app', [], ['app.no_such_service' => new \stdClass()]);

        self::assertInstanceOf(BootFailure::class, $boot);
        self::assertSame(['bad_replacement'], array_column($boot->problems->json(), 'code'));
    }

    public function testAReplacementOfTheWrongTypeIsABootProblem(): void
    {
        $boot = TestApp::bootFixture('handler-app', [], [ClockInterface::class => new \DateTimeImmutable()]);

        self::assertInstanceOf(BootFailure::class, $boot);
        self::assertSame(['bad_replacement'], array_column($boot->problems->json(), 'code'));
        self::assertSame(\DateTimeImmutable::class, $boot->problems->problems()[0]->context['given']);
    }

    public function testReplacementsGivenAsAListAreABootProblemShowingTheKeyedForm(): void
    {
        // Lava Notes (R2-B12): an integer key reached the strictly typed has()
        // and the boot failed as unexpected_failure, blaming Container.php.
        $boot = TestApp::bootFixture('handler-app', [], [new FrozenClock('2026-09-13 09:00:00')]);

        self::assertInstanceOf(BootFailure::class, $boot);
        self::assertSame(['bad_replacement'], array_column($boot->problems->json(), 'code'));
        $problem = $boot->problems->problems()[0];
        self::assertSame(0, $problem->context['key']);
        self::assertSame(FrozenClock::class, $problem->context['given']);
        self::assertStringContainsString('[Id::class => $replacement]', $problem->fix);
    }

    public function testTheContainerAnswersForAReplacedIdAndForWhatDependsOnIt(): void
    {
        $fake = new \ArrayObject(['fake' => true]);
        $container = new Container(['app.store' => $fake]);
        $container->singleton('app.store', static fn (): \ArrayObject => new \ArrayObject(['real' => true]));
        $container->alias('app.store_alias', 'app.store');
        $container->singleton('app.reader', static fn (Container $c): array => ['store' => $c->get('app.store')]);

        self::assertSame($fake, $container->get('app.store'));
        self::assertSame($fake, $container->get('app.store_alias'));
        self::assertSame(['store' => $fake], $container->get('app.reader'));
        self::assertSame([], $container->replacementProblems());
    }

    public function testTheContainerReportsEveryReplacementThatCannotMeanWhatItSays(): void
    {
        $container = new Container([
            'app.nothing' => 1,
            ClockInterface::class => 'not a clock',
        ]);
        $container->singleton(ClockInterface::class, static fn (): ClockInterface => new SystemClock());

        $problems = $container->replacementProblems();

        self::assertCount(2, $problems);
        self::assertContainsOnlyInstancesOf(BadReplacement::class, $problems);
        self::assertSame('app.nothing', $problems[0]->context['id']);
        self::assertSame('string', $problems[1]->context['given']);
    }

    public function testAFrozenClockMovesOnlyWhenTold(): void
    {
        $clock = new FrozenClock('2026-01-01 00:00:00');
        self::assertSame($clock->now(), $clock->now());

        $clock->advance(new \DateInterval('P1D'));
        self::assertSame('2026-01-02 00:00:00', $clock->now()->format('Y-m-d H:i:s'));

        $clock->set('2030-06-01 12:00:00');
        self::assertSame('2030-06-01 12:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    /** @param array<string, mixed> $replace */
    private static function boot(string $fixture, array $replace = []): App
    {
        $app = TestApp::bootFixture($fixture, [], $replace);
        self::assertInstanceOf(
            App::class,
            $app,
            $app instanceof BootFailure ? json_encode($app->problems->json(), JSON_PRETTY_PRINT) ?: '' : '',
        );

        return $app;
    }
}
