<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

use Lava\Core\Boot\App;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

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
     * Dispatch with this client's cookies, and keep whatever the response sets.
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
    private function send(ServerRequestInterface $request): TestResponse
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