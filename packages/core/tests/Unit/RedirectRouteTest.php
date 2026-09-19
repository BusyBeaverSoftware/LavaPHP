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
use Lava\Core\Testing\TestConsole;
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
            'shadowing a wider target' => [
                static function (Router $r): void {
                    $r->redirect('/{section:str}/{slug:str}', 'old', to: 'pages.show');
                    $r->get('/pages/{slug:str}', 'pages.show')->handler(['App\Nowhere', 'show']);
                },
                "is registered before 'pages.show' and its path also matches the URLs of 'pages.show', such as '/pages/a'",
                "Register 'old' after 'pages.show'",
            ],
            'the same addresses as its target' => [
                static function (Router $r): void {
                    $r->redirect('/pages/{slug:str}', 'old', to: 'pages.show');
                    $r->get('/pages/{slug:str}', 'pages.show')->handler(['App\Nowhere', 'show']);
                },
                "it would answer that address with itself",
                'the two paths match the same addresses',
            ],
            'unreachable behind its target' => [
                static function (Router $r): void {
                    $r->get('/pages/{slug:str}', 'pages.show')->handler(['App\Nowhere', 'show']);
                    $r->redirect('/pages/{slug:str}', 'old', to: 'pages.show');
                },
                "can never match: 'pages.show' is registered first",
                "already answers those addresses",
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

    public function testARedirectToARouteWhoseGateIsOffIsAbsentWithIt(): void
    {
        // Lava Notes R3-B5: it answered 301 into a 404.
        $off = (new TestClient(self::app()))->get('/b/hello');
        self::assertSame(404, $off->status());
        self::assertFalse($off->hasHeader('Location'));
        self::assertSame('route_not_found', $off->json()['problems'][0]['code']);

        $app = TestApp::bootFixture('redirect-app', ['LAVA_FEATURE_BETA_POSTS' => 'on']);
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');
        $on = (new TestClient($app))->get('/b/hello');
        self::assertSame([301, '/beta/hello'], [$on->status(), $on->header('Location')]);
    }

    public function testARedirectAskedForTheAddressItLeadsToFailsAtItsLineInsteadOfLooping(): void
    {
        // Lava Notes R3-B4: `/g/{section:str}/{slug:str}` also matches the URLs of
        // `/g/pages/{slug:str}`, and is registered first.
        $client = new TestClient(self::app());

        $moved = $client->get('/g/intro/hello');
        self::assertSame([301, '/g/pages/hello'], [$moved->status(), $moved->header('Location')]);

        $loop = $client->get('/g/pages/hello');
        self::assertSame(500, $loop->status());
        self::assertFalse($loop->hasHeader('Location'));
        $problem = $loop->json()['problems'][0];
        self::assertSame('bad_redirect', $problem['code']);
        self::assertStringContainsString("'guides.section' leads to '/g/pages/hello', the address it was asked for", $problem['problem']);
        self::assertStringEndsWith('redirect-app/app/Routes.php', $problem['source']['file']);
        self::assertSame(self::lineOf("'guides.section'"), $problem['source']['line']);
    }

    public function testACapturedValueStartingWithASlashNeverSendsTheVisitorToAnotherHost(): void
    {
        // Lava Notes R3-B1: this answered `Location: //evil.example/x`.
        $response = (new TestClient(self::app()))->get('/docs//evil.example/x');

        self::assertSame([301, '/%2Fevil.example/x'], [$response->status(), $response->header('Location')]);
    }

    public function testDescribeNamesWhereARedirectLeads(): void
    {
        // Lava Notes R3-G3: only the map said.
        $console = new TestConsole(TestApp::autoloadFixture('redirect-app'));

        self::assertSame(['to' => 'posts.show', 'status' => 308], $console->json('describe', 'posts.dated')->data()['match']['redirect']);
        self::assertNull($console->json('describe', 'posts.show')->data()['match']['redirect']);
    }

    private static function lineOf(string $needle): int
    {
        foreach (file(TestApp::fixturePath('redirect-app') . '/app/Routes.php') ?: [] as $i => $text) {
            if (str_contains($text, $needle)) {
                return $i + 1;
            }
        }
        self::fail("No line of the fixture's routes contains {$needle}.");
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
