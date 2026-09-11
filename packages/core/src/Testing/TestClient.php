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

    /** @param array<string, string> $headers */
    public function head(string $path, array $headers = []): TestResponse
    {
        return $this->request('HEAD', $path, $headers);
    }
}