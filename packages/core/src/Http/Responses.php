<?php

declare(strict_types=1);

namespace Lava\Core\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

/**
 * The boring way to build responses. Every handler returns one of these —
 * no response subclasses, no fluent builders, five named constructors.
 */
final class Responses
{
    private static ?Psr17Factory $factory = null;

    public static function json(mixed $data, int $status = 200): ResponseInterface
    {
        // Compact on the wire (agents parse it; humans get the diagnostics
        // page). Pretty-printing, when it exists, belongs to the CLI's text
        // rendering, not to HTTP bodies.
        // SUBSTITUTE, as HttpErrors already does: a route param can carry bytes
        // that are not UTF-8, and a handler echoing one must not become a 500
        // (security review, F3). HEX_TAG and HEX_AMP keep a body safe to embed
        // in a page, which JSON_UNESCAPED_SLASHES alone does not.
        $body = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );
        return self::text($body, $status)->withHeader('Content-Type', 'application/json');
    }

    public static function text(string $text, int $status = 200): ResponseInterface
    {
        return self::response($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody(self::factory()->createStream($text));
    }

    public static function html(string $html, int $status = 200): ResponseInterface
    {
        return self::response($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody(self::factory()->createStream($html));
    }

    public static function redirect(string $location, int $status = 302): ResponseInterface
    {
        return self::response($status)->withHeader('Location', $location);
    }

    public static function noContent(): ResponseInterface
    {
        return self::response(204);
    }

    private static function response(int $status): ResponseInterface
    {
        return self::factory()->createResponse($status);
    }

    private static function factory(): Psr17Factory
    {
        return self::$factory ??= new Psr17Factory();
    }
}