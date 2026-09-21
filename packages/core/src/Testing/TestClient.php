<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

use Lava\Core\Boot\App;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Dispatches PSR-7 requests against a booted App — no network, no SAPI;
 * the app's handle() is the whole server for tests.
 *
 * It keeps cookies between requests the way a browser does, so a test can sign
 * in through the real form and stay signed in. Each client has its own jar: a
 * second `new TestClient($app)` is a second visitor.
 */
final class TestClient
{
    private readonly CookieJar $cookies;

    public function __construct(private readonly App $app)
    {
        $this->cookies = new CookieJar();
    }

    /**
     * The cookies this client holds — every `Set-Cookie` a response sent, until
     * a later response removes it. Inspect it, seed it, or clear it.
     */
    public function cookies(): CookieJar
    {
        return $this->cookies;
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, array $headers = []): TestResponse
    {
        return $this->send(new ServerRequest(strtoupper($method), $path, $headers));
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): TestResponse
    {
        return $this->request('GET', $path, $headers);
    }

    /** @param array<string, string> $headers */
    public function post(string $path, array $headers = []): TestResponse
    {
        return $this->request('POST', $path, $headers);
    }

    /** @param array<string, string> $headers */
    public function put(string $path, array $headers = []): TestResponse
    {
        return $this->request('PUT', $path, $headers);
    }

    /** @param array<string, string> $headers */
    public function patch(string $path, array $headers = []): TestResponse
    {
        return $this->request('PATCH', $path, $headers);
    }

    /** @param array<string, string> $headers */
    public function delete(string $path, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $path, $headers);
    }

    /**
     * A request with a JSON body, parsed — `$request->getParsedBody()` returns
     * the array, exactly as it does for a real request.
     *
     * The body is set as well as the parsed form, because that is what a real
     * request looks like: a handler that reads the raw stream (a webhook
     * verifying a signature, say) sees the same bytes here as in production.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function json(string $method, string $path, array $data, array $headers = []): TestResponse
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->withBody(
            strtoupper($method),
            $path,
            $encoded,
            $data,
            ['Content-Type' => 'application/json'] + $headers,
        );
    }

    /**
     * A request with a form-encoded body. `$request->getParsedBody()` returns
     * the array; the stream carries the encoded form, which is what a handler
     * reading the raw body would get from a browser.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function form(string $method, string $path, array $data, array $headers = []): TestResponse
    {
        return $this->withBody(
            strtoupper($method),
            $path,
            http_build_query($data),
            $data,
            ['Content-Type' => 'application/x-www-form-urlencoded'] + $headers,
        );
    }

    /**
     * A multipart request carrying uploaded files, as a browser sends a form
     * with `<input type="file">`.
     *
     * Each file is a filesystem path, an `UploadedFileInterface` built by the
     * test, or `['bytes' => …, 'name' => …, 'type' => …]` for content that never
     * touched a disk. Pass an `UploadedFile` directly to test an error status —
     * `UPLOAD_ERR_INI_SIZE` is the one an app must handle and cannot otherwise
     * reproduce.
     *
     * ```php
     * $response = $client->upload('POST', '/admin/media', ['cover' => __DIR__ . '/fixtures/cover.png'], ['alt' => 'A cover']);
     * ```
     *
     * The body stream is left EMPTY on purpose, because that is what a handler
     * sees in production: PHP parses a multipart body itself into `$_POST` and
     * `$_FILES`, and `php://input` is empty for that content type. A client that
     * wrote the encoded body anyway would be more generous than the server, and
     * would hide a handler that reads the raw stream. `Content-Length` is the
     * size the encoded body would have, because that header does arrive — core
     * reads it to tell an oversized form from an empty one.
     *
     * @param array<string, string|UploadedFileInterface|array{bytes: string, name?: string, type?: string}> $files
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     */
    public function upload(string $method, string $path, array $files, array $fields = [], array $headers = []): TestResponse
    {
        $boundary = '----LavaTestBoundary' . bin2hex(random_bytes(8));
        $uploads = [];
        $encoded = '';

        foreach ($fields as $name => $value) {
            $encoded .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
                . (is_scalar($value) ? (string) $value : (string) json_encode($value)) . "\r\n";
        }

        foreach ($files as $name => $file) {
            $upload = self::uploadFrom($file, (string) $name);
            $uploads[$name] = $upload;
            $filename = $upload->getClientFilename() ?? (string) $name;
            $type = $upload->getClientMediaType() ?? 'application/octet-stream';
            $encoded .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: {$type}\r\n\r\n" . self::bytesOf($upload) . "\r\n";
        }

        $encoded .= "--{$boundary}--\r\n";

        $request = (new ServerRequest(strtoupper($method), $path, [
            'Content-Type' => "multipart/form-data; boundary={$boundary}",
            'Content-Length' => (string) strlen($encoded),
        ] + $headers))
            ->withParsedBody($fields)
            ->withUploadedFiles($uploads);

        return $this->send($request);
    }

    /**
     * A request whose body is exactly these bytes, under exactly this content
     * type, with nothing parsed for the handler in advance.
     *
     * That last part is the difference from {@see json()}: `json()` sets the
     * parsed body itself, so a handler receives the array whatever the framework
     * would have done with the bytes. `raw()` sets only the stream, so core's own
     * body handling runs — which is how a test proves what the framework does
     * with a body rather than what the client did for it. It is also the shape a
     * webhook receiver needs, where the signature is over the bytes as sent.
     *
     * ```php
     * $client->raw('POST', '/hooks/payments', $payload, 'application/json', ['Signature' => $mac]);
     * ```
     *
     * @param array<string, string> $headers
     */
    public function raw(string $method, string $path, string $body, string $contentType, array $headers = []): TestResponse
    {
        $request = new ServerRequest(strtoupper($method), $path, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($body),
        ] + $headers);
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        return $this->send($request);
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, string> $headers
     */
    private function withBody(string $method, string $path, string $body, array $parsed, array $headers): TestResponse
    {
        $request = (new ServerRequest($method, $path, $headers))->withParsedBody($parsed);
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        return $this->send($request);
    }

    /**
     * One uploaded file from whatever a test handed over.
     *
     * @param string|UploadedFileInterface|array{bytes: string, name?: string, type?: string} $file
     */
    private static function uploadFrom(string|array|UploadedFileInterface $file, string $field): UploadedFileInterface
    {
        if ($file instanceof UploadedFileInterface) {
            return $file;
        }

        if (is_array($file)) {
            $bytes = $file['bytes'];

            return new UploadedFile(
                Stream::create($bytes),
                strlen($bytes),
                \UPLOAD_ERR_OK,
                $file['name'] ?? $field,
                $file['type'] ?? 'application/octet-stream',
            );
        }

        if (!is_file($file)) {
            throw new \InvalidArgumentException(
                "TestClient::upload() was given '{$file}' for '{$field}', and no file is there."
                . " Pass a path that exists, or ['bytes' => …] for content with no file behind it.",
            );
        }

        return new UploadedFile(
            $file,
            (int) filesize($file),
            \UPLOAD_ERR_OK,
            basename($file),
            self::mediaTypeOf($file),
        );
    }

    /** The bytes of an upload, with the stream left where a handler expects it. */
    private static function bytesOf(UploadedFileInterface $upload): string
    {
        if ($upload->getError() !== \UPLOAD_ERR_OK) {
            // A failed upload has no readable stream, and PSR-7 allows reading
            // one to throw. The part is still on the wire; its content is not.
            return '';
        }

        $stream = $upload->getStream();
        $bytes = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $bytes;
    }

    private static function mediaTypeOf(string $path): string
    {
        // What the CLIENT claims, which is what `getClientMediaType()` means: a
        // browser guesses from the extension and can be lied to. An app that
        // sniffs the bytes instead (docs/uploads.md) is doing the safer thing,
        // and a test that fills this in from the real file still exercises it.
        $sniffed = function_exists('mime_content_type') ? @mime_content_type($path) : false;

        return is_string($sniffed) && $sniffed !== '' ? $sniffed : 'application/octet-stream';
    }

    /**
     * Dispatch a request the test built itself, with this client's cookies.
     *
     * Public, because it is the only method that applies the jar: a test needing
     * a shape the named helpers do not cover — an unusual header, a method with
     * a body, a URI with a query this class does not build — otherwise has to
     * construct the request AND hand it to `App::handle()` directly, which drops
     * the cookies and with them the "sign in through the real form" discipline
     * the rest of this class exists to keep (Lava Notes round 4, R4-G2). Prefer
     * a named helper where one fits: it is what a reader recognises.
     *
     * The cookies travel in both places a real request carries them: the
     * `Cookie` header, and `getCookieParams()` — which a `new ServerRequest()`
     * leaves empty and PHP fills from `$_COOKIE` in production. Filling only
     * the header would make a reader of the params work in a browser and see
     * nothing under test, which is the hardest kind of test failure to explain.
     *
     * A `Cookie` header the test passed itself joins the jar's cookies and wins
     * for a name both have — it is the more specific instruction.
     */
    public function send(ServerRequestInterface $request): TestResponse
    {
        $path = $request->getUri()->getPath();
        $cookies = self::cookiesIn($request->getHeaderLine('Cookie')) + $this->cookies->forPath($path);

        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $name => $value) {
                $pairs[] = "{$name}={$value}";
            }
            $request = $request
                ->withHeader('Cookie', implode('; ', $pairs))
                // `urldecode`, not `rawurldecode`: it is what PHP applies to
                // `$_COOKIE`, `+` included, so the params match production.
                ->withCookieParams(array_map(urldecode(...), $cookies));
        }

        $response = $this->app->handle($request);
        $this->cookies->absorb($response, $path);

        return new TestResponse($response);
    }

    /**
     * The name => value pairs of a `Cookie` header, first occurrence winning.
     *
     * @return array<string, string>
     */
    private static function cookiesIn(string $header): array
    {
        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            $equals = strpos($pair, '=');
            if ($equals === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $equals));
            if ($name !== '') {
                $cookies[$name] ??= trim(substr($pair, $equals + 1));
            }
        }

        return $cookies;
    }

    /** @param array<string, string> $headers */
    public function head(string $path, array $headers = []): TestResponse
    {
        return $this->request('HEAD', $path, $headers);
    }
}
