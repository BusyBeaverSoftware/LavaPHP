<?php

declare(strict_types=1);

namespace App\Tests;

use Lava\Core\Boot\App;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * This app's executable spec, and what `lava test` / `lava check` actually run.
 *
 * The harness boots the app in-process — no server, no network — and dispatches
 * PSR-7 requests through the same handler the HTTP entry point uses, so a test
 * proves the real request path rather than a simulation of it.
 */
final class HealthTest extends TestCase
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

    public function testTheTypedParameterArrivesAsAString(): void
    {
        $response = (new TestClient(self::$app))->get('/hello/Ada');

        self::assertSame(200, $response->status());
        self::assertSame(['hello' => 'Hello, Ada!'], $response->json());
    }

    public function testTheGatedRouteIsReachableWhileItsFlagIsOn(): void
    {
        $response = (new TestClient(self::$app))->get('/beta/hello');

        self::assertSame(200, $response->status());
        self::assertSame(['hello' => 'Hello, beta!'], $response->json());
    }

    public function testAnUnknownPathIsA404ProblemNotA500(): void
    {
        $response = (new TestClient(self::$app))->get('/nope');

        self::assertSame(404, $response->status());
        self::assertSame('route_not_found', $response->json()['problems'][0]['code']);
    }
}
