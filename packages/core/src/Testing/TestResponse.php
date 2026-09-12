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

    /**
     * Every value of a header, one entry per line as the response sent them.
     *
     * `header()` joins repeated lines with a comma, which is right for most
     * headers and wrong for `Set-Cookie`: a response that sets two cookies sends
     * two lines, and an `Expires` date already contains a comma, so the joined
     * form cannot be split back apart.
     *
     * @return list<string>
     */
    public function headers(string $name): array
    {
        return array_values($this->response->getHeader($name));
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