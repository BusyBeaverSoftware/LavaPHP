<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\UpstreamServer;
use Lava\Core\Boot\App;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * `GET /upstream/health`: the one route that leaves this process, and the only
 * place in this app where a failure is raised by a pack rather than by the app.
 *
 * Every test here boots the app with `UPSTREAM_URL` pointing at a real `php -S`
 * this suite starts. That is the whole reason the config value is
 * environment-overridable: the demo must exercise `lava/http-client` against
 * something genuinely running without depending on the internet, which would
 * make this suite fail offline and flake online.
 *
 * What the boot-and-dispatch shape proves that a unit test of `HttpClient`
 * cannot: that `config/http_client.php` is read, that the module turns it into
 * options, that `app/Services.php` composes the pack's client into an app
 * service, that the handler resolves through `ValidateWiring` at boot, and that
 * a problem raised three layers down still reaches the client in the app's own
 * envelope. A pack can pass every unit test and still be unusable in an app
 * because its ids do not resolve; this file is what catches that.
 */
final class UpstreamTest extends TestCase
{
    private static UpstreamServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = UpstreamServer::shared();
    }

    /**
     * The app, booted against one upstream behaviour.
     *
     * The database is `sqlite::memory:` for the same reason `TasksTest` uses it
     * — the route does not touch it, but a boot that reaches for the default
     * file DSN would leave `var/demo.sqlite` behind on a machine that has one.
     */
    private static function clientFor(string $behaviour): TestClient
    {
        $app = TestApp::boot(dirname(__DIR__), [
            'DATABASE_DSN' => 'sqlite::memory:',
            'UPSTREAM_URL' => self::$server->url($behaviour),
        ]);

        if (!$app instanceof App) {
            $lines = [];
            foreach ($app->problems->problems() as $problem) {
                $lines[] = $problem->code() . ': ' . $problem->getMessage();
            }
            self::fail("the demo did not boot:\n  " . implode("\n  ", $lines));
        }

        return new TestClient($app);
    }

    public function testTheUpstreamAnswerIsProxiedThrough(): void
    {
        $response = self::clientFor('ok')->get('/upstream/health');

        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'ok'], $response->json());
    }

    public function testTheConfiguredUserAgentReachesTheUpstream(): void
    {
        // `config/http_client.php` sets `user_agent => 'lava-demo/1.0'`, and
        // this reads it back from the other end of a socket. Nothing short of a
        // real request shows that config reached the wire rather than only the
        // options object.
        $decoded = self::clientFor('echo')->get('/upstream/health')->json();
        self::assertIsArray($decoded);

        self::assertSame('GET', $decoded['method']);
        self::assertSame('lava-demo/1.0', $decoded['user_agent']);
    }

    public function testANonTwoHundredIsThePacksOwnProblem(): void
    {
        // The upstream answers 500. The handler has no error branch — the pack
        // raises `unexpected_status` and `App::handle()` renders it — so what
        // arrives is the framework's envelope, with a 502 (a bad answer from a
        // dependency is not this app's fault) and a fix picked from the status.
        $response = self::clientFor('broken')->get('/upstream/health');

        self::assertSame(502, $response->status());

        $decoded = $response->json();
        self::assertIsArray($decoded);
        $problems = $decoded['problems'];
        self::assertIsArray($problems);
        $first = $problems[0];
        self::assertIsArray($first);

        self::assertSame('unexpected_status', $first['code']);
        self::assertSame(500, $first['context']['status']);
        self::assertSame('/broken/health', parse_url((string) $first['context']['url'], PHP_URL_PATH));
    }

    public function testAnIdempotentFetchIsRetriedUntilAnAnswerArrives(): void
    {
        // `config/http_client.php` sets `retries => 2`, and the upstream drops
        // the connection on the first request. The counter lives in the
        // server's own process, so this is the attempt count and not the
        // client's opinion of it.
        $id = UpstreamServer::flakyId();
        $response = self::clientFor("flaky-{$id}")->get('/upstream/health');

        self::assertSame(200, $response->status());
        self::assertSame(2, UpstreamServer::attempts($id), 'a GET gets a second chance');
    }

    public function testAResponseThatNeverArrivesIsATransportFailure(): void
    {
        // The same failure as above, with nothing behind it to succeed: the
        // pack exhausts its attempts and reports the reason. `transport_failed`
        // is a 502 as well, because a dependency that cannot be reached is not
        // this app's fault either.
        $response = self::clientFor('drop')->get('/upstream/health');

        self::assertSame(502, $response->status());

        $decoded = $response->json();
        self::assertIsArray($decoded);
        $problems = $decoded['problems'];
        self::assertIsArray($problems);
        $first = $problems[0];
        self::assertIsArray($first);

        self::assertSame('transport_failed', $first['code']);
    }

    public function testTheCounterFilePrefixAgreesWithTheServer(): void
    {
        // The harness and the router are two processes with no shared autoloader,
        // so the prefix exists twice. This is what keeps the copies honest: if
        // either drifts, the retry test above silently reads a counter nobody
        // wrote and passes for the wrong reason.
        $router = (string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/upstream/router.php');

        self::assertStringContainsString(UpstreamServer::COUNTER_PREFIX, $router);
    }
}
