<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Problem\SourceLocation;

/** A middleware entry is not a PSR-15 middleware class-string. */
final class BadMiddleware extends LavaProblem
{
    public function code(): string
    {
        return 'bad_middleware';
    }

    public static function missing(string $class, string $where): self
    {
        return new self(
            "Middleware class '{$class}' does not exist (used by {$where}).",
            "Install the package that provides it, or remove it from the middleware list.",
            ['middleware' => $class, 'used_by' => $where],
        );
    }

    public static function notPsr15(string $class, string $where): self
    {
        return new self(
            "Middleware '{$class}' (used by {$where}) does not implement PSR-15 MiddlewareInterface.",
            "Make it implement \Psr\Http\Server\MiddlewareInterface with"
            . ' process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface.',
            ['middleware' => $class, 'used_by' => $where],
        );
    }
}