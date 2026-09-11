<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Http\HttpErrors;
use Lava\Core\Problem\ProblemCliRenderer;
use Lava\Core\Problem\ProblemJsonRenderer;
use Lava\Core\Problem\ProblemReport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Boot failed. Holds every collected problem — never just the first one —
 * and renders the identical report as terminal text, JSON, or an HTTP
 * diagnostics response.
 */
final class BootFailure
{
    public function __construct(
        public readonly ProblemReport $problems,
        public readonly string $appDir,
        public readonly string $env = 'dev',
    ) {
    }

    public function text(): string
    {
        return (new ProblemCliRenderer())->render($this->problems);
    }

    public function json(): string
    {
        return (new ProblemJsonRenderer())->render($this->problems);
    }

    /** The same report as an HTTP response (500) — diagnostics page or JSON by Accept. */
    public function toResponse(?ServerRequestInterface $request = null): ResponseInterface
    {
        return HttpErrors::reportToResponse($this->problems, 500, $request, $this->env);
    }
}