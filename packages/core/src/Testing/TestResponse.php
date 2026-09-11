<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

use Psr\Http\Message\ResponseInterface;

/** Assertion-friendly accessors over a PSR-7 response. */
final class TestResponse
{
    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function raw(): ResponseInterface
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    public function body(): string
    {
        $body = $this->response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        return $body->getContents();
    }

    /** @return mixed the decoded JSON body (arrays are associative) */
    public function json(): mixed
    {
        return json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
    }
}