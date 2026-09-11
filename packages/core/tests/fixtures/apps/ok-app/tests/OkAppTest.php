<?php

declare(strict_types=1);

namespace App\Tests;

use Lava\Core\Boot\App;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * ok-app's suite — the fixture's executable spec, and the thing `lava test` and
 * `lava check` actually run. A real app's suite looks exactly like this: boot
 * through the harness, dispatch PSR-7 requests, assert on the response. No
 * network, no SAPI — `$app->handle()` is the whole server.
 */
final class OkAppTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        $app = TestApp::boot(dirname(__DIR__));
        self::assertInstanceOf(App::class, $app);
        self::$app = $app;
    }

    public function testHealthAnswersJson(): void
    {
        $response = (new TestClient(self::$app))->get('/health');

        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'ok'], $response->json());
    }

    public function testTypedParamIsConvertedAndRouteMiddlewareRuns(): void
    {
        $response = (new TestClient(self::$app))->get('/users/42');

        self::assertSame(200, $response->status());
        self::assertSame('yes', $response->header('X-Auth'));
        // Global middleware runs first and wraps the route's own — the order
        // log is the app's proof of that, asserted here rather than assumed.
        self::assertSame(['global', 'auth'], $response->json()['order']);
        self::assertSame(['id' => 42], $response->json()['user']);
    }

    public function testUnknownPathIsA404ProblemNotA500(): void
    {
        $response = (new TestClient(self::$app))->get('/nope');

        self::assertSame(404, $response->status());
        self::assertSame('route_not_found', $response->json()['problems'][0]['code']);
    }
}
