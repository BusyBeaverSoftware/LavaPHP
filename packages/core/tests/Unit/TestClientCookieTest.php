<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * TestClient carries cookies between requests the way a browser does.
 *
 * Every assertion reads what the HANDLER saw — cookie-app's `show` echoes both
 * `getCookieParams()` and the `Cookie` header — rather than what the jar holds,
 * because the jar is a means: the claim is that a test observes what production
 * observes.
 */
final class TestClientCookieTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        $app = TestApp::bootFixture('cookie-app');
        self::assertInstanceOf(App::class, $app);
        self::$app = $app;
    }

    public function testACookieAResponseSetsIsSentOnTheNextRequest(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/session/start');

        $seen = $client->get('/session/show')->json();

        self::assertSame('abc123', $seen['params']['session']);
        self::assertStringContainsString('session=abc123', $seen['header']);
    }

    public function testParamsAreDecodedAsPhpDecodesCookiesAndTheHeaderIsNot(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/session/start');

        $seen = $client->get('/session/show')->json();

        self::assertSame('hello world', $seen['params']['note']);
        self::assertStringContainsString('note=hello%20world', $seen['header']);
        self::assertSame('hello%20world', $client->cookies()->get('note'));
    }

    public function testMaxAgeZeroRemovesTheCookie(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/session/start');
        $client->get('/session/end');

        $seen = $client->get('/session/show')->json();

        self::assertArrayNotHasKey('session', $seen['params']);
        self::assertArrayHasKey('note', $seen['params']); // only the one it named
    }

    public function testAnExpiresInThePastRemovesTheCookie(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/session/start');
        $client->get('/session/expired');

        self::assertArrayNotHasKey('note', $client->get('/session/show')->json()['params']);
    }

    public function testAPathAttributeScopesTheCookie(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/admin/start');

        self::assertSame(['admin' => 'yes'], $client->get('/admin/show')->json()['params']);
        self::assertSame([], $client->get('/session/show')->json()['params']);
        // A prefix of the characters is not a prefix of the path.
        self::assertSame([], $client->get('/administrator/show')->json()['params']);
    }

    public function testWithoutAPathTheCookieIsScopedToTheRequestDirectory(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/account/settings/theme');

        self::assertSame(['theme' => 'dark'], $client->get('/account/settings/show')->json()['params']);
        self::assertSame([], $client->get('/account/show')->json()['params']);
    }

    public function testAnExplicitCookieHeaderReachesTheParamsAndWinsOverTheJar(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/session/start');

        $seen = $client->get('/session/show', ['Cookie' => 'session=mine; extra=1'])->json();

        self::assertSame('mine', $seen['params']['session']);
        self::assertSame('1', $seen['params']['extra']);
        self::assertSame('hello world', $seen['params']['note']); // the jar's own, still sent
    }

    public function testRequestsWithABodyCarryCookiesToo(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/session/start');

        $seen = $client->form('POST', '/session/show', ['title' => 'x'])->json();

        self::assertSame('abc123', $seen['params']['session']);
    }

    public function testEveryClientIsANewVisitor(): void
    {
        $first = new TestClient(self::$app);
        $first->get('/session/start');

        $seen = (new TestClient(self::$app))->get('/session/show')->json();

        self::assertSame([], $seen['params']);
        self::assertSame('', $seen['header']);
    }

    public function testTheJarCanBeClearedAndSeeded(): void
    {
        $client = new TestClient(self::$app);
        $client->get('/session/start');

        $client->cookies()->clear();
        self::assertSame([], $client->get('/session/show')->json()['params']);

        $client->cookies()->set('session', 'seeded');
        self::assertSame(['session' => 'seeded'], $client->get('/session/show')->json()['params']);
    }

    public function testEverySetCookieLineIsReadableOnItsOwn(): void
    {
        $response = (new TestClient(self::$app))->get('/session/start');

        $lines = $response->headers('Set-Cookie');

        self::assertCount(2, $lines);
        self::assertSame('session=abc123; Path=/; HttpOnly; SameSite=Lax', $lines[0]);
        self::assertStringContainsString('Expires=Wed, 01 Jan 2098', $lines[1]);
    }
}
