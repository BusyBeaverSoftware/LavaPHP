<?php

declare(strict_types=1);

namespace App\Tests;

use Lava\Core\Boot\App;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Lava\Db\Connection;
use Lava\Db\Migration\MigrationFiles;
use Lava\Db\Migration\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * This app's executable spec, and what `lava test` / `lava check` actually run.
 *
 * The harness boots the app in-process — no server, no network — and dispatches
 * PSR-7 requests through the same handler the HTTP entry point uses, so these
 * prove the real request path rather than a simulation of it.
 *
 * The database is `sqlite::memory:`, which lives exactly as long as its
 * connection. The container holds ONE connection for the whole process, so the
 * schema survives from request to request and evaporates when the suite ends:
 * no file to clean up, and no way for a test to reach a developer's data. The
 * schema itself comes from the real migration, run by the same runner
 * `lava db:migrate` uses — never a hand-written approximation of the table.
 */
final class TasksTest extends TestCase
{
    private static App $app;
    private static Connection $db;
    private static TestClient $client;

    public static function setUpBeforeClass(): void
    {
        $app = TestApp::boot(dirname(__DIR__), ['DATABASE_DSN' => 'sqlite::memory:']);
        self::assertInstanceOf(App::class, $app);

        $connection = $app->container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        (new MigrationRunner($connection, MigrationFiles::inApp($app->appDir)))->migrate();

        self::$app = $app;
        self::$db = $connection;
        self::$client = new TestClient($app);
    }

    protected function setUp(): void
    {
        // Every test starts from an empty table. `whereRaw('1 = 1')` is the
        // builder's explicit spelling of "all rows" — an unbounded DELETE is
        // refused, so "every one of them" has to be said out loud.
        self::$db->run(self::$db->table('tasks')->whereRaw('1 = 1')->delete());
    }

    public function testHealthAnswersJson(): void
    {
        $response = self::$client->get('/health');

        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'ok'], $response->json());
    }

    public function testAnEmptyListIsAnEmptyArrayNotNull(): void
    {
        $response = self::$client->get('/tasks');

        self::assertSame(200, $response->status());
        // `[]`, never `{}`: a caller that iterates the list must not also have
        // to branch on which flavour of empty it was handed.
        self::assertSame(['tasks' => []], $response->json());
    }

    public function testStoringATaskReturnsItWithAnId(): void
    {
        $response = self::$client->json('POST', '/tasks', [
            'title' => 'Ship it',
            'due_on' => '2026-12-31',
        ]);

        self::assertSame(201, $response->status(), $response->body());

        $task = $response->json()['task'];
        self::assertIsInt($task['id']);
        self::assertSame('Ship it', $task['title']);
        self::assertSame('2026-12-31', $task['due_on']);
        self::assertFalse($task['done']);
    }

    /**
     * The pillar, as a test: one round trip carries every bad field.
     *
     * Fixing one field per request is the failure mode this framework exists to
     * avoid, so the assertion is not just "422" — it is that BOTH fields are
     * named, each with its own imperative fix, in a single response.
     */
    public function testEveryBadFieldIsReportedAtOnce(): void
    {
        $response = self::$client->json('POST', '/tasks', [
            'title' => '',
            'due_on' => 'not-a-date',
        ]);

        self::assertSame(422, $response->status());

        $problems = $response->json()['problems'];
        self::assertCount(2, $problems);
        self::assertSame(['validation_failed', 'validation_failed'], array_column($problems, 'code'));
        self::assertSame(
            ['title', 'due_on'],
            array_map(static fn (array $problem): string => (string) $problem['context']['field'], $problems),
        );
        self::assertSame(
            ['required', 'regex'],
            array_map(static fn (array $problem): string => (string) $problem['context']['rule'], $problems),
        );

        foreach ($problems as $problem) {
            self::assertNotSame('', $problem['fix'], 'every problem carries a fix, not just a message');
        }
    }

    public function testAnUnknownFieldCannotSetAColumn(): void
    {
        $response = self::$client->json('POST', '/tasks', [
            'title' => 'Honest',
            'done' => true,
        ]);

        self::assertSame(201, $response->status(), $response->body());
        // The field map is the allow-list: `done` was never declared, so it was
        // dropped rather than written. A caller cannot set a column by guessing
        // its name.
        self::assertFalse($response->json()['task']['done']);
    }

    public function testAMissingTaskIsA404ProblemNotA500(): void
    {
        $response = self::$client->get('/tasks/4242');

        self::assertSame(404, $response->status());

        $problem = $response->json()['problems'][0];
        self::assertSame('task_not_found', $problem['code']);
        self::assertSame(['id' => 4242], $problem['context']);
        self::assertNotSame('', $problem['fix']);
    }

    public function testCompletingATaskFlipsIt(): void
    {
        $id = $this->create('Write the tests');

        $response = self::$client->post("/tasks/{$id}/complete");

        self::assertSame(200, $response->status(), $response->body());
        self::assertTrue($response->json()['task']['done']);
        self::assertTrue(self::$client->get("/tasks/{$id}")->json()['task']['done']);
    }

    public function testDeletingATaskRemovesItAndASecondDeleteIsA404(): void
    {
        $id = $this->create('Throw me away');

        $first = self::$client->delete("/tasks/{$id}");
        self::assertSame(204, $first->status());
        self::assertSame('', $first->body());

        $second = self::$client->delete("/tasks/{$id}");
        self::assertSame(404, $second->status());
        self::assertSame('task_not_found', $second->json()['problems'][0]['code']);
    }

    public function testTheListFiltersByDoneAndIsNewestFirst(): void
    {
        $open = $this->create('Still open');
        $finished = $this->create('Already done');
        self::$client->post("/tasks/{$finished}/complete");

        self::assertSame(
            [$finished, $open],
            array_column(self::$client->get('/tasks')->json()['tasks'], 'id'),
        );
        self::assertSame(
            [$open],
            array_column(self::$client->get('/tasks?done=open')->json()['tasks'], 'id'),
        );
        self::assertSame(
            [$finished],
            array_column(self::$client->get('/tasks?done=done')->json()['tasks'], 'id'),
        );
    }

    public function testTheExportRouteAnswersCsvWhileItsFlagIsOn(): void
    {
        $this->create('Ship it', '2026-12-31');

        $response = self::$client->get('/tasks/export');

        self::assertSame(200, $response->status());
        self::assertSame('text/csv; charset=utf-8', $response->header('Content-Type'));

        $lines = explode("\n", trim($response->body()));
        self::assertSame('id,title,done,due_on,created_at', $lines[0]);

        // Parsed rather than string-matched. `fputcsv` encloses any field that
        // contains a space, so the second line reads `7,"Ship it",0,…` — which
        // is correct CSV, and a raw `assertStringContainsString` would be
        // asserting PHP's quoting rules instead of the data.
        $row = str_getcsv($lines[1], escape: '');
        self::assertCount(5, $row);
        self::assertSame('Ship it', $row[1]);
        self::assertSame('0', $row[2], 'a boolean has to be spelled out — fputcsv writes false as ""');
        self::assertSame('2026-12-31', $row[3]);
    }

    /**
     * The middleware is global, so it runs on every request in this file — this
     * is the one place its contract is pinned.
     */
    public function testAWellFormedRequestIdIsKeptAndAGarbageOneIsReplaced(): void
    {
        $kept = self::$client->get('/health', ['X-Request-Id' => 'abc-123_XY.z']);
        self::assertSame('abc-123_XY.z', $kept->header('X-Request-Id'));

        // A caller-supplied header is input like any other. Echoing it back
        // unchecked would make every response a mirror for whatever was sent,
        // and put unvalidated text into every log line downstream.
        $replaced = self::$client->get('/health', ['X-Request-Id' => 'bad id with spaces']);
        self::assertNotSame('bad id with spaces', $replaced->header('X-Request-Id'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $replaced->header('X-Request-Id'));
    }

    private function create(string $title, ?string $dueOn = null): int
    {
        $body = ['title' => $title];

        if ($dueOn !== null) {
            $body['due_on'] = $dueOn;
        }

        $response = self::$client->json('POST', '/tasks', $body);
        self::assertSame(201, $response->status(), $response->body());

        return (int) $response->json()['task']['id'];
    }
}
