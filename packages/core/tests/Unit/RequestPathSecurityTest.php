<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * The request path, after the decode entry 314 introduced (security review,
 * findings F1-F3).
 *
 * 0.5.0 decoded the path for matching and left the request carrying the
 * original, so the router and every middleware read different strings: a guard
 * refusing `/admin` never saw `/%61dmin`, which the router routed to the very
 * route the guard existed to protect. The same decode delivered `../` and NUL
 * into route params, which no conforming client can send literally.
 */
final class RequestPathSecurityTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/lava-path-security-' . bin2hex(random_bytes(6));
        mkdir(self::$dir . '/app', 0777, true);
        file_put_contents(self::$dir . '/app/Services.php', <<<'PHP'
            <?php
            // A global middleware guarding a prefix — the ordinary PSR-15 shape
            // conventions.md describes for a sign-in gate. Declared here because
            // app/Services.php is read before anything resolves it.
            if (!class_exists('LavaSecurityPrefixGuard')) {
                final class LavaSecurityPrefixGuard implements \Psr\Http\Server\MiddlewareInterface
                {
                    public function process(\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface
                    {
                        if (str_starts_with($request->getUri()->getPath(), '/admin')) {
                            return \Lava\Core\Http\Responses::json(['denied' => 'login required'], 403);
                        }

                        return $handler->handle($request);
                    }
                }
            }
            return function (\Lava\Core\Container\Container $c, \Lava\Core\Boot\AppContext $ctx): void {
                $c->singleton('LavaSecurityPrefixGuard', static fn (): object => new \LavaSecurityPrefixGuard());
            };
            PHP);
        file_put_contents(self::$dir . '/app/Routes.php', <<<'PHP'
            <?php
            if (!function_exists('lava_security_echo')) {
                function lava_security_echo(\Lava\Core\Routing\RouteArgs $args, \Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return \Lava\Core\Http\Responses::json([
                        'route' => $args->routeName,
                        'args' => $args->all(),
                        'seen_path' => $request->getUri()->getPath(),
                    ]);
                }
            }
            return function (\Lava\Core\Routing\Router $r): void {
                $r->get('/admin/panel', 'admin.panel')->handler('lava_security_echo');
                $r->get('/docs/{rest:path}', 'docs')->handler('lava_security_echo');
                $r->get('/hello/{name:str}', 'hello')->handler('lava_security_echo');
            };
            PHP);
        file_put_contents(self::$dir . '/app/Middleware.php', "<?php\nreturn ['LavaSecurityPrefixGuard'];\n");
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/app/Routes.php');
        @unlink(self::$dir . '/app/Services.php');
        @unlink(self::$dir . '/app/Middleware.php');
        @rmdir(self::$dir . '/app');
        @rmdir(self::$dir);
    }

    private static function client(): TestClient
    {
        $app = TestApp::boot(self::$dir);
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        return new TestClient($app);
    }

    public function testAnEncodedPathCannotSlipPastAMiddlewareThatGuardsIt(): void
    {
        $client = self::client();

        // The guard's own case, for the control.
        self::assertSame(403, $client->get('/admin/panel')->status());

        // 0.5.0 answered 200 here: the middleware read `/%61dmin/panel` while
        // the router matched the decoded `/admin/panel`.
        foreach (['/%61dmin/panel', '/%61%64%6d%69%6e/panel', '/admin/%70anel'] as $encoded) {
            $response = $client->get($encoded);
            self::assertSame(403, $response->status(), $encoded);
            self::assertSame('login required', $response->json()['denied'], $encoded);
        }
    }

    public function testTheRequestCarriesTheCanonicalFormOfWhatTheRouterMatched(): void
    {
        $client = self::client();

        // An over-encoded ASCII character is the dangerous case, and the one
        // that resolves: the request now says `/hello/abc`, which is what the
        // router matched, so no guard can be shown a different string.
        $normalised = $client->get('/hello/%61bc');
        self::assertSame('/hello/abc', $normalised->json()['seen_path']);
        self::assertSame('abc', $normalised->json()['args']['name']);

        // A non-ASCII byte stays percent-encoded, because a URI carries it that
        // way and PSR-7 re-encodes what it is given. The handler still receives
        // the decoded value; a middleware comparing paths compares this form.
        $unicode = $client->get('/hello/caf%C3%A9');
        self::assertSame(200, $unicode->status());
        self::assertSame('/hello/caf%C3%A9', $unicode->json()['seen_path']);
        self::assertSame('café', $unicode->json()['args']['name']);
    }

    public function testAnEncodedDotSegmentIsRefusedRatherThanDelivered(): void
    {
        $client = self::client();

        // A conforming client collapses a literal `../` before sending, so an
        // app never saw one — until the decode delivered `%2e%2e%2f` as one.
        foreach (['/docs/%2e%2e%2fsecret.txt', '/docs/%2E%2E/secret.txt', '/%2e%2e/admin/panel', '/docs/a/%2e/b'] as $path) {
            $response = $client->get($path);
            self::assertSame(400, $response->status(), $path);
            self::assertSame('bad_request_path', $response->json()['problems'][0]['code'], $path);
        }

        // A dot INSIDE a segment is an ordinary name and still routes.
        self::assertSame(200, $client->get('/docs/release-1.2.txt')->status());
        self::assertSame('..secret', $client->get('/docs/..secret')->json()['args']['rest']);
    }

    public function testADecodedControlByteIsRefused(): void
    {
        $client = self::client();

        foreach (['/hello/a%00b', '/hello/a%0d%0ab', '/hello/a%09b'] as $path) {
            $response = $client->get($path);
            self::assertSame(400, $response->status(), $path);
            self::assertSame('bad_request_path', $response->json()['problems'][0]['code'], $path);
        }
    }

    public function testBytesThatAreNotUtf8SurviveAsAResponseRatherThanA500(): void
    {
        // json_encode threw on invalid UTF-8, so any JSON route echoing a param
        // was a one-request 500 for anyone who sent `%ff`.
        $response = self::client()->get('/hello/%ff%fe');

        self::assertSame(200, $response->status(), $response->body());
        self::assertSame('hello', $response->json()['route']);
    }
}
