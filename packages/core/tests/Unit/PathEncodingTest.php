<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * One encoding, at the two ends (Lava Notes, R3-B3): `UrlGenerator` encodes what
 * it puts in a URL, and `App` decodes the path once before matching. This drives
 * both halves through a real request, because the bug lived between them — the
 * router alone was consistent, and a handler still received `caf%C3%A9`.
 */
final class PathEncodingTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/lava-path-encoding-' . bin2hex(random_bytes(6));
        mkdir(self::$dir . '/app', 0777, true);
        file_put_contents(self::$dir . '/app/Routes.php', <<<'PHP'
            <?php
            if (!function_exists('lava_encoding_echo')) {
                function lava_encoding_echo(\Lava\Core\Routing\RouteArgs $args): \Psr\Http\Message\ResponseInterface
                {
                    return \Lava\Core\Http\Responses::json(['route' => $args->routeName, 'args' => $args->all()]);
                }
            }
            return function (\Lava\Core\Routing\Router $r): void {
                $r->pattern('tag', '[a-z#]+');
                $r->get('/search/{q:str}', 'search')->handler('lava_encoding_echo');
                $r->get('/files/{rest:path}', 'files')->handler('lava_encoding_echo');
                $r->get('/tags/{name:tag}', 'tags.show')->handler('lava_encoding_echo');
                $r->get('/café', 'cafe')->handler('lava_encoding_echo');
            };
            PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/app/Routes.php');
        @rmdir(self::$dir . '/app');
        @rmdir(self::$dir);
    }

    private static function app(): App
    {
        $app = TestApp::boot(self::$dir);
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        return $app;
    }

    public function testAValueSurvivesTheRoundTripThroughAGeneratedUrl(): void
    {
        $app = self::app();
        $client = new TestClient($app);
        $url = $app->container->get(\Lava\Core\Routing\UrlGenerator::class);
        self::assertInstanceOf(\Lava\Core\Routing\UrlGenerator::class, $url);

        $cases = [
            ['search', 'q', 'a?b', '/search/a%3Fb'],
            ['search', 'q', 'a b', '/search/a%20b'],
            ['search', 'q', 'café', '/search/caf%C3%A9'],
            ['search', 'q', '100%', '/search/100%25'],
            ['search', 'q', 'a#b', '/search/a%23b'],
            ['tags.show', 'name', 'c#', '/tags/c%23'],
            ['files', 'rest', 'tech/php.txt', '/files/tech/php.txt'],
        ];

        foreach ($cases as [$route, $param, $value, $expected]) {
            $generated = $url->url($route, [$param => $value]);
            self::assertSame($expected, $generated, "url({$route}, {$value})");

            $response = $client->get($generated);
            self::assertSame(200, $response->status(), $generated . ' ' . $response->body());
            self::assertSame(['route' => $route, 'args' => [$param => $value]], $response->json(), $generated);
        }
    }

    public function testAStaticNonAsciiPathMatchesTheEncodedRequest(): void
    {
        $client = new TestClient(self::app());

        // What a browser sends for a link to `/café`. Before the fix this was a
        // 404: the compiled route held the raw bytes and the path held `%C3%A9`.
        $encoded = $client->get('/caf%C3%A9');
        self::assertSame(200, $encoded->status(), $encoded->body());
        self::assertSame('cafe', $encoded->json()['route']);

        self::assertSame(200, $client->get('/café')->status(), 'Raw bytes still match.');
    }

    public function testAnEncodedSlashDoesNotSmuggleASegmentIntoAStrParam(): void
    {
        $client = new TestClient(self::app());

        // `%2F` decodes before the type sees it, so `str` ([^/]+) refuses it and
        // the path matches nothing rather than handing a handler `a/b`.
        self::assertSame(404, $client->get('/search/a%2Fb')->status());
        self::assertSame('tech/php.txt', $client->get('/files/tech%2Fphp.txt')->json()['args']['rest'], 'A spanning type takes it.');
    }
}
