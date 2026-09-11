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
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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