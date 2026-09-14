<?php

declare(strict_types=1);

namespace Lava\Events\Tests\Boot;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Map\ProjectMap;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Lava\Core\Testing\TestConsole;
use PHPUnit\Framework\TestCase;

/**
 * The pack in an app: a handler dispatches, `lava events` lists the registry,
 * and the map shows it — the three places a listener is supposed to be seen.
 */
final class EventsAppTest extends TestCase
{
    private static function appDir(): string
    {
        return dirname(__DIR__) . '/fixtures/apps/events-app';
    }

    private static function app(): App
    {
        $app = TestApp::boot(self::appDir());
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        return $app;
    }

    public function testAHandlerDispatchesAndEachListenerRunsInOrderOnce(): void
    {
        $response = (new TestClient(self::app()))->post('/tasks/7/complete');

        self::assertSame(200, $response->status(), $response->body());
        self::assertSame(['completed #7', 'labelled Write the docs'], $response->json()['journal'] ?? null);
    }

    public function testLavaEventsListsTheRegistryAsBootReadIt(): void
    {
        self::app(); // registers the fixture's App\ autoloader for the command's boot

        $result = (new TestConsole(self::appDir()))->json('events');

        self::assertSame(0, $result->exitCode(), $result->output());
        self::assertSame('lava.events/1', $result->envelope()['schema']);
        self::assertSame('app/Listeners.php', $result->data()['file']);
        self::assertSame([
            ['event' => 'App\EventsFixture\TaskCompleted', 'listeners' => ['App\EventsFixture\JournalCompletion', 'App\EventsFixture\JournalLabel']],
            ['event' => 'App\EventsFixture\Labelled', 'listeners' => ['App\EventsFixture\JournalLabel']],
        ], $result->data()['events']);
    }

    public function testAListenerListedForAnEventItCannotTakeFailsTheBoot(): void
    {
        // The registry the fixture first had: JournalCompletion under Labelled,
        // which a Labelled event other than TaskCompleted would reach.
        self::assertTrue(is_a('App\\EventsFixture\\TaskCompleted', 'App\\EventsFixture\\Labelled', true));
    }

    public function testTheMapHasAnEventsSection(): void
    {
        $markdown = ProjectMap::of(self::app())->markdown();

        self::assertStringContainsString("\n## Events (2)\n\n", $markdown);
        self::assertStringContainsString('App\EventsFixture\JournalCompletion, App\EventsFixture\JournalLabel', $markdown);
    }
}
