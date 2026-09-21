<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * The bodies a test can send: an upload, raw bytes, and a request it built
 * itself (Lava Notes round 4, R4-G2).
 *
 * docs/uploads.md is a page about accepting files, and until now this client
 * could not send one — so the one request in an admin app that takes
 * attacker-supplied bytes was the one request the framework's own client could
 * not reach. Every consumer hand-built PSR-7 requests instead, which loses the
 * cookies and with them the "sign in through the real form" discipline.
 */
final class TestClientBodiesTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/lava-client-bodies-' . bin2hex(random_bytes(6));
        mkdir(self::$dir . '/app', 0777, true);
        file_put_contents(self::$dir . '/app/Routes.php', <<<'PHP'
            <?php
            if (!function_exists('lava_bodies_upload')) {
                function lava_bodies_upload(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    $files = [];
                    foreach ($request->getUploadedFiles() as $field => $file) {
                        $files[$field] = [
                            'name' => $file->getClientFilename(),
                            'type' => $file->getClientMediaType(),
                            'size' => $file->getSize(),
                            'error' => $file->getError(),
                            'sha' => $file->getError() === \UPLOAD_ERR_OK ? hash('sha256', (string) $file->getStream()) : null,
                        ];
                    }

                    return \Lava\Core\Http\Responses::json([
                        'files' => $files,
                        'fields' => $request->getParsedBody(),
                        // Empty in production for a multipart body: PHP parses it
                        // into $_POST/$_FILES and leaves php://input empty.
                        'raw_bytes' => strlen((string) $request->getBody()),
                    ]);
                }
                function lava_bodies_raw(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    $raw = (string) $request->getBody();

                    return \Lava\Core\Http\Responses::json([
                        'raw' => $raw,
                        // Hashed as well as echoed: a JSON body substitutes
                        // invalid UTF-8, so bytes that are not text can only be
                        // compared through a digest.
                        'raw_sha' => hash('sha256', $raw),
                        'raw_len' => strlen($raw),
                        'type' => $request->getHeaderLine('Content-Type'),
                        'signature' => $request->getHeaderLine('Signature'),
                        'parsed' => $request->getParsedBody(),
                    ]);
                }
                function lava_bodies_cookies(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return \Lava\Core\Http\Responses::json([
                        'params' => $request->getCookieParams(),
                        'header' => $request->getHeaderLine('Cookie'),
                    ]);
                }
            }
            return function (\Lava\Core\Routing\Router $r): void {
                $r->post('/upload', 'upload')->handler('lava_bodies_upload');
                $r->post('/raw', 'raw')->handler('lava_bodies_raw');
                $r->get('/cookies', 'cookies')->handler('lava_bodies_cookies');
            };
            PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/app/Routes.php');
        @rmdir(self::$dir . '/app');
        @rmdir(self::$dir);
    }

    private static function client(): TestClient
    {
        $app = TestApp::boot(self::$dir);
        self::assertInstanceOf(App::class, $app, $app instanceof BootFailure ? $app->text() : '');

        return new TestClient($app);
    }

    public function testAnUploadFromAPathArrivesAsTheHandlerWouldSeeItInProduction(): void
    {
        // A one-pixel PNG, written to a real file, so the path branch is the one
        // an app's own test would take.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
        $path = self::$dir . '/cover.png';
        file_put_contents($path, $png);

        try {
            $response = self::client()->upload('POST', '/upload', ['cover' => $path], ['alt' => 'A cover', 'position' => 3]);

            self::assertSame(200, $response->status(), $response->body());
            $body = $response->json();

            self::assertSame('cover.png', $body['files']['cover']['name']);
            self::assertSame('image/png', $body['files']['cover']['type'], 'The client media type is sniffed from the real file.');
            self::assertSame(strlen($png), $body['files']['cover']['size']);
            self::assertSame(\UPLOAD_ERR_OK, $body['files']['cover']['error']);
            self::assertSame(hash('sha256', $png), $body['files']['cover']['sha'], 'The handler read the bytes that went in.');

            // Fields travel as a browser sends them, beside the file.
            self::assertSame(['alt' => 'A cover', 'position' => 3], $body['fields']);

            // Deliberate: PHP leaves php://input empty for multipart, so this
            // client does too rather than being more generous than the server.
            self::assertSame(0, $body['raw_bytes']);
        } finally {
            @unlink($path);
        }
    }

    public function testAnUploadCanBeBytesWithNoFileBehindIt(): void
    {
        $response = self::client()->upload('POST', '/upload', [
            'notes' => ['bytes' => "line one\nline two", 'name' => 'notes.txt', 'type' => 'text/plain'],
        ]);

        $file = $response->json()['files']['notes'];
        self::assertSame(['notes.txt', 'text/plain', 17], [$file['name'], $file['type'], $file['size']]);
        self::assertSame(hash('sha256', "line one\nline two"), $file['sha']);
    }

    public function testAnUploadErrorTheServerWouldReportCanBeTested(): void
    {
        // The case an app must handle and cannot otherwise reach: PHP refused the
        // file itself, so there is a field with an error and no readable stream.
        $refused = new UploadedFile(Stream::create(''), 0, \UPLOAD_ERR_INI_SIZE, 'huge.png', 'image/png');

        $file = self::client()->upload('POST', '/upload', ['cover' => $refused])->json()['files']['cover'];

        self::assertSame(\UPLOAD_ERR_INI_SIZE, $file['error']);
        self::assertNull($file['sha'], 'A refused upload has no bytes to read.');
        self::assertSame('huge.png', $file['name']);
    }

    public function testAMissingUploadPathIsRefusedWithTheTwoWaysToPassOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("no file is there");
        self::client()->upload('POST', '/upload', ['cover' => self::$dir . '/not-here.png']);
    }

    public function testRawSendsExactlyTheBytesGivenAndLetsTheFrameworkParseThem(): void
    {
        $client = self::client();
        $payload = '{"amount":1250,"currency":"eur"}';

        $response = $client->raw('POST', '/raw', $payload, 'application/json', ['Signature' => 'sha256=abc']);

        $body = $response->json();
        self::assertSame($payload, $body['raw'], 'The bytes a signature is computed over arrive unchanged.');
        self::assertSame('application/json', $body['type']);
        self::assertSame('sha256=abc', $body['signature']);
        // Nothing was parsed by the client: core's own body handling did it,
        // which is what makes this the shape that proves framework behaviour.
        self::assertSame(['amount' => 1250, 'currency' => 'eur'], $body['parsed']);
    }

    public function testRawCarriesBytesUnderAnyContentTypeUntouched(): void
    {
        $bytes = "\x00\x01\x02binary\xff";

        $body = self::client()->raw('POST', '/raw', $bytes, 'application/octet-stream')->json();

        // Compared by digest: the bytes reached the handler intact, and the JSON
        // response then substituted the ones that are not valid UTF-8 — which is
        // the response encoder doing its job, not the client altering the body.
        self::assertSame(hash('sha256', $bytes), $body['raw_sha']);
        self::assertSame(strlen($bytes), $body['raw_len']);
        self::assertNull($body['parsed'], 'A body core does not claim to parse reaches the handler unparsed.');
    }

    public function testAJsonListIsRefusedAsNotAnObject(): void
    {
        // R4-B1: docs/problem-codes.md and lava-validate.md both say a body that
        // is valid JSON and not an object is refused; `is_array()` let a list
        // through, and every endpoint paid for the difference with its own guard.
        $response = self::client()->raw('POST', '/raw', '[1,2,3]', 'application/json');

        self::assertSame(400, $response->status());
        $problem = $response->json()['problems'][0];
        self::assertSame('malformed_body', $problem['code']);
        self::assertStringContainsString('is a list', $problem['problem']);
        self::assertStringContainsString('{"items": […]}', $problem['fix']);
        self::assertSame('a list', $problem['context']['decoded_as']);
    }

    public function testAnEmptyJsonObjectIsStillAcceptedBecauseItDecodesLikeAnEmptyList(): void
    {
        // `{}` and `[]` are the same PHP value, so refusing the list would refuse
        // the object too. Validation naming the missing fields is the better
        // answer than a parse error.
        $response = self::client()->raw('POST', '/raw', '{}', 'application/json');

        self::assertSame(200, $response->status(), $response->body());
        self::assertSame([], $response->json()['parsed']);
    }

    public function testARequestTheTestBuiltItselfStillCarriesTheClientsCookies(): void
    {
        $client = self::client();
        $client->cookies()->set('session', 'abc123');

        // The point of a public send(): this shape is not one of the named
        // helpers, and before it a test had to call App::handle() directly and
        // lose the jar.
        $response = $client->send(new ServerRequest('GET', '/cookies', ['X-Custom' => 'yes']));

        self::assertSame(200, $response->status(), $response->body());
        self::assertSame(['session' => 'abc123'], $response->json()['params']);
        self::assertStringContainsString('session=abc123', $response->json()['header']);
    }
}
