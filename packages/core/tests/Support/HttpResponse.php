<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A real HTTP response, fetched with stream contexts — no curl extension, no
 * HTTP client library. `ignore_errors` is the important flag: 404 and 405 are
 * expected outcomes of these tests, not transport failures, and a client that
 * treated them as errors could not assert on the problem bodies at all.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function fetch(string $url, string $method, string $path, array $headers = []): self
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $lines,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $body = @file_get_contents($url . $path, false, $context);
        if ($body === false) {
            return new self(0, [], '');
        }

        $status = 0;
        $parsed = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                continue;
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($name))] = trim($value);
            }
        }

        return new self($status, $parsed, $body);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        Assert::assertIsArray($decoded, 'body was not a JSON object: ' . $this->body);
        return $decoded;
    }

    /** The first problem in a JSON problem body, as `{"problems":[…]}` carries it. */
    public function problem(): array
    {
        $problems = $this->json()['problems'] ?? null;
        Assert::assertIsArray($problems, 'body carried no problems array: ' . $this->body);
        Assert::assertArrayHasKey(0, $problems, 'body carried an empty problems array');
        Assert::assertIsArray($problems[0]);
        return $problems[0];
    }

    public function problemCode(): mixed
    {
        return $this->problem()['code'] ?? null;
    }
}
