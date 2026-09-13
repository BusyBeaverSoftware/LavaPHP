<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Map\ProjectMap;
use Lava\Core\Problem\BadRedirect;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Routing\Router;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * `$r->redirect()`: an old address that answers with a redirect to the route
 * that replaced it (Lava Notes, R2-G13).
 */
final class RedirectRouteTest extends TestCase
{
    private static function app(): App
    {
        $app = TestApp::bootFixture('redirect-app');
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        return $app;
    }

    /** @return list<LavaProblem> */
    private static function problems(\Closure $routes, ?Router &$router = null): array
    {
        $router = new Router();
        $routes($router);
        $report = new ProblemReport();
        $router->finalize($report);

        return $report->problems();
    }

    public function testARedirectAnswersWithTheTargetsUrlFilledFromItsOwnParamsAndKeepsTheQuery(): void
    {
        $client = new TestClient(self::app());

        $short = $client->get('/p/hello?ref=feed');
        self::assertSame([301, '/posts/hello?ref=feed'], [$short->status(), $short->header('Location')]);

        $dated = $client->get('/2026/hello');
        self::assertSame([308, '/posts/hello'], [$dated->status(), $dated->header('Location')], 'Only the params the target has.');

        $latest = $client->get('/latest');
        self::assertSame([302, '/'], [$latest->status(), $latest->header('Location')]);

        self::assertSame(301, $client->head('/p/hello')->status());
        self::assertSame(405, $client->post('/p/hello')->status(), 'GET and HEAD only.');
        self::assertSame('post hello', $client->get('/posts/hello')->body());
    }

    public function testTheMapAndTheRouteListNameTheTarget(): void
    {
        $app = self::app();

        $handlers = array_column(ProjectMap::of($app)->routes, 'handler', 'name');
        self::assertSame('redirect to posts.show (301)', $handlers['posts.short']);
        self::assertSame('redirect to posts.show (308)', $handlers['posts.dated']);
        self::assertSame(['to' => 'home', 'status' => 302], $app->router->redirectTarget('latest'));
        self::assertNull($app->router->redirectTarget('home'));
    }

    public function testATargetThatCannotBeReachedIsAProblemAndTheRedirectIsNotCompiled(): void
    {
        $cases = [
            'unknown' => [
                static function (Router $r): void {
                    $r->get('/posts/{slug:str}', 'posts.show')->handler(['App\Nowhere', 'show']);
                    $r->redirect('/p/{slug:str}', 'old', to: 'post.show');
                },
                "no route has that name",
                "Did you mean 'posts.show'?",
            ],
            'not GET' => [
                static function (Router $r): void {
                    $r->post('/save', 'save')->handler(['App\Nowhere', 'save']);
                    $r->redirect('/old-save', 'old', to: 'save');
                },
                'answers POST but not GET',
                'follows a redirect with GET',
            ],
            'a chain' => [
                static function (Router $r): void {
                    $r->get('/', 'home')->handler(['App\Nowhere', 'home']);
                    $r->redirect('/start', 'start', to: 'home');
                    $r->redirect('/old', 'old', to: 'start');
                },
                "'start', which is itself a redirect",
                "Point it at 'home'",
            ],
            'a param it does not capture' => [
                static function (Router $r): void {
                    $r->get('/posts/{slug:str}', 'posts.show')->handler(['App\Nowhere', 'show']);
                    $r->redirect('/old/{id:int}', 'old', to: 'posts.show');
                },
                "cannot fill the param 'slug' of 'posts.show': its path captures {id:int}",
                '{slug:str}',
            ],
            'a param of another type' => [
                static function (Router $r): void {
                    $r->get('/posts/{id:int}', 'posts.show')->handler(['App\Nowhere', 'show']);
                    $r->redirect('/old/{id:str}', 'old', to: 'posts.show');
                },
                "captures 'id' as str, and 'posts.show' needs it as int",
                '{id:int}',
            ],
        ];

        foreach ($cases as $case => [$routes, $message, $fix]) {
            $problems = self::problems($routes, $router);

            self::assertCount(1, $problems, $case);
            self::assertInstanceOf(BadRedirect::class, $problems[0], $case);
            self::assertStringContainsString($message, $problems[0]->getMessage(), $case);
            self::assertStringContainsString($fix, $problems[0]->fix, $case);
            self::assertStringEndsWith('RedirectRouteTest.php', (string) $problems[0]->source?->file, $case);
            self::assertInstanceOf(Router::class, $router);
            self::assertNotContains('old', $router->names(), $case);
            self::assertNull($router->redirectTarget('old'), $case);
        }
    }

    public function testAStatusThatIsNotARedirectIsRefusedWhereItIsWritten(): void
    {
        try {
            (new Router())->redirect('/old', 'old', to: 'home', status: 200);
            self::fail('A redirect answering 200 is not a redirect.');
        } catch (BadRedirect $problem) {
            self::assertSame('bad_redirect', $problem->code());
            self::assertStringContainsString('status 200, which is not a redirect', $problem->getMessage());
            self::assertStringEndsWith('RedirectRouteTest.php', (string) $problem->source?->file);
        }
    }
}
