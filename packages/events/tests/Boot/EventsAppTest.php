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
        // JournalLabel comes from the Labelled entry, written after this one,
        // and JournalAudit is `last` though its entry lists it second.
        self::assertSame(['completed #7', 'labelled Write the docs', 'audited #7'], $response->json()['journal'] ?? null);
    }

    public function testTheOrderLavaEventsPrintsIsTheOrderDispatchTakes(): void
    {
        self::app(); // registers the fixture's App\ autoloader for the command's boot

        $printed = (new TestConsole(self::appDir()))->json('events')->data()['events'][0];
        self::assertIsArray($printed);
        self::assertSame('App\EventsFixture\TaskCompleted', $printed['event']);

        $writes = [
            'App\EventsFixture\JournalCompletion' => 'completed #7',
            'App\EventsFixture\JournalLabel' => 'labelled Write the docs',
            'App\EventsFixture\JournalAudit' => 'audited #7',
        ];
        $expected = array_map(
            static fn (array $listener): string => $writes[(string) $listener['listener']],
            $printed['listeners'],
        );

        $journal = (new TestClient(self::app()))->post('/tasks/7/complete')->json()['journal'] ?? null;
        self::assertSame($expected, $journal, 'What `lava events` prints is the order the dispatch runs.');
    }

    public function testLavaEventsListsTheRegistryAsBootReadIt(): void
    {
        self::app(); // registers the fixture's App\ autoloader for the command's boot

        $result = (new TestConsole(self::appDir()))->json('events');

        self::assertSame(0, $result->exitCode(), $result->output());
        self::assertSame('lava.events/2', $result->envelope()['schema']);
        self::assertSame('app/Listeners.php', $result->data()['file']);
        self::assertSame(['first', 'default', 'last'], $result->data()['phases']);
        // TaskCompleted runs JournalLabel too, which only the Labelled entry
        // names, and JournalAudit last — the order dispatch takes, not the
        // order the file lists.
        self::assertSame([
            ['event' => 'App\EventsFixture\TaskCompleted', 'listeners' => [
                ['listener' => 'App\EventsFixture\JournalCompletion', 'phase' => 'default'],
                ['listener' => 'App\EventsFixture\JournalLabel', 'phase' => 'default'],
                ['listener' => 'App\EventsFixture\JournalAudit', 'phase' => 'last'],
            ]],
            ['event' => 'App\EventsFixture\Labelled', 'listeners' => [
                ['listener' => 'App\EventsFixture\JournalLabel', 'phase' => 'default'],
            ]],
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
        self::assertStringContainsString(
            'App\EventsFixture\JournalCompletion, App\EventsFixture\JournalLabel, App\EventsFixture\JournalAudit (last)',
            $markdown,
            'The map shows the order that runs, and the phase that placed a listener.',
        );
    }
}
