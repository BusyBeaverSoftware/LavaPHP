<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

use Lava\Core\Boot\App;
use Nyholm\Psr7\ServerRequest;

/**
 * Dispatches PSR-7 requests against a booted App — no network, no SAPI;
 * the app's handle() is the whole server for tests.
 */
final class TestClient
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, array $headers = []): TestResponse
    {
        $request = new ServerRequest(strtoupper($method), $path, $headers);
        return new TestResponse($this->app->handle($request));
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

        return new TestResponse($this->app->handle($request));
    }

    /** @param array<string, string> $headers */
    public function head(string $path, array $headers = []): TestResponse
    {
        return $this->request('HEAD', $path, $headers);
    }
}