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
 * The HTML surface: what `lavaphp/view` does for this app, proven over the same
 * in-process dispatch path the JSON suite uses.
 *
 * Four things are worth a test here, and each of them is a claim the pack makes
 * that a reader of `views/` cannot verify by looking:
 *
 *  - a title that arrived from a client is escaped, so a template cannot be an
 *    XSS hole;
 *  - `url()` produces the path the ROUTER would match, so a link cannot point
 *    somewhere that 404s;
 *  - `feature()` and the route's own gate answer from the same resolver, so the
 *    export link and the export route appear and disappear together;
 *  - a missing task is the app's own `task_not_found` problem, not a blank page.
 *
 * The template directory is the app's real `views/`, read through a real boot —
 * so a template that does not parse fails here rather than in a browser.
 */
final class PagesTest extends TestCase
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
        self::$db->run(self::$db->table('tasks')->whereRaw('1 = 1')->delete());
    }

    public function testTheBoardRendersHtmlAndSaysHowManyTasksThereAre(): void
    {
        $response = self::$client->get('/');

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('text/html', $response->header('Content-Type'));
        self::assertStringContainsString('No tasks yet.', $response->body());
    }

    public function testATitleFromAClientIsEscaped(): void
    {
        // The value would be dangerous unescaped, which is what makes this a
        // test rather than a formatting assertion.
        self::$client->json('POST', '/tasks', ['title' => '<script>alert(1)</script>']);

        $body = self::$client->get('/')->body();

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    public function testTheBoardLinksToEachTaskByRouteName(): void
    {
        $created = self::$client->json('POST', '/tasks', ['title' => 'Ship it'])->json();
        self::assertIsArray($created);
        $id = $created['task']['id'];
        self::assertIsInt($id);

        $body = self::$client->get('/')->body();

        // The literal paths `url()` produced, not the route names it was given:
        // a link is only correct if it is what the router would match.
        self::assertStringContainsString("href=\"/tasks/{$id}\"", $body);
        self::assertStringContainsString("href=\"/tasks/{$id}/view\"", $body);
        self::assertStringContainsString('href="/upstream/health"', $body);
    }

    public function testTheTaskPageRendersTheTask(): void
    {
        $created = self::$client->json('POST', '/tasks', ['title' => 'Ship it'])->json();
        self::assertIsArray($created);
        $id = $created['task']['id'];
        self::assertIsInt($id);

        $response = self::$client->get("/tasks/{$id}/view");

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('text/html', $response->header('Content-Type'));
        self::assertStringContainsString('Ship it', $response->body());
        self::assertStringContainsString('href="/"', $response->body());
    }

    public function testAMissingTaskIsTheAppsOwnProblemNotABlankPage(): void
    {
        // No `Accept` header, so the caller gets JSON — the same answer the
        // JSON route gives, from the same problem class. One failure, one code,
        // one fix, whichever representation was asked for.
        $response = self::$client->get('/tasks/999/view');

        self::assertSame(404, $response->status());

        $decoded = $response->json();
        self::assertIsArray($decoded);
        $problems = $decoded['problems'];
        self::assertIsArray($problems);
        $first = $problems[0];
        self::assertIsArray($first);
        self::assertSame('task_not_found', $first['code']);
    }

    public function testTheExportLinkAndTheExportRouteAreGatedTogether(): void
    {
        // One flag, two consequences, and the point is that they come from the
        // same resolver: `feature()` in the template and `->when()` on the
        // route both ask `Features`, so the page can never advertise a link the
        // router would 404.
        $on = self::$client->get('/');
        self::assertStringContainsString('href="/tasks/export"', $on->body());
        self::assertSame(200, self::$client->get('/tasks/export')->status());

        $off = TestApp::boot(dirname(__DIR__), [
            'DATABASE_DSN' => 'sqlite::memory:',
            'LAVA_FEATURE_TASKS_CSV_EXPORT' => 'off',
        ]);
        self::assertInstanceOf(App::class, $off);
        $offClient = new TestClient($off);

        self::assertStringNotContainsString('href="/tasks/export"', $offClient->get('/')->body());
        self::assertSame(404, $offClient->get('/tasks/export')->status());
    }

    public function testTheTemplatesTheAppNamesAreTheTemplatesThatExist(): void
    {
        // `ViewRenderer::exists()` is what a handler would use to choose
        // between rendering a page and answering a 404, so asserting it here
        // catches a renamed template before a handler depends on it.
        $view = self::$app->container->get(\Lava\View\ViewRenderer::class);
        self::assertInstanceOf(\Lava\View\ViewRenderer::class, $view);

        self::assertTrue($view->exists('home'));
        self::assertTrue($view->exists('task'));
        self::assertTrue($view->exists('layout'));
        self::assertFalse($view->exists('does/not/exist'));
        self::assertStringEndsWith('/views', $view->templateDir());
    }
}
