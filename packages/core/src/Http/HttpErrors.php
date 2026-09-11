<?php

declare(strict_types=1);

namespace Lava\Core\Http;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Problems → HTTP responses, identical content in both media:
 * JSON `{"problems": […]}` for API clients, the hand-escaped diagnostics
 * page for browsers. `curl` (no useful Accept) gets JSON — agents first.
 */
final class HttpErrors
{
    public static function toResponse(LavaProblem $problem, ?ServerRequestInterface $request = null, string $env = 'dev'): ResponseInterface
    {
        $report = new ProblemReport();
        $report->add($problem);
        $response = self::reportToResponse($report, self::statusFor($problem), $request, $env);
        if ($problem->code() === 'method_not_allowed'
            && isset($problem->context['allowed'])
            && is_array($problem->context['allowed'])) {
            // A 405 must say what IS allowed (RFC 9110 §15.5.5).
            $response = $response->withHeader('Allow', implode(', ', $problem->context['allowed']));
        }
        return $response;
    }

    public static function reportToResponse(
        ProblemReport $report,
        int $status,
        ?ServerRequestInterface $request = null,
        string $env = 'dev',
    ): ResponseInterface {
        if (self::wantsJson($request)) {
            return Responses::json(['problems' => $report->json()], $status);
        }
        return Responses::html(DiagnosticsPage::render($report, $env), $status);
    }

    /** Runtime problem codes that have a dedicated HTTP status; everything else is a 500. */
    private static function statusFor(LavaProblem $problem): int
    {
        return match ($problem->code()) {
            'route_not_found' => 404,
            'method_not_allowed' => 405,
            default => 500,
        };
    }

    /**
     * Positional Accept negotiation: whichever of json/html appears first
     * wins; neither present (empty header or a wildcard — curl, agents,
     * tests) → JSON.
     */
    private static function wantsJson(?ServerRequestInterface $request): bool
    {
        if ($request === null) {
            return true;
        }
        $accept = $request->getHeaderLine('Accept');
        $json = strpos($accept, 'json');
        $html = strpos($accept, 'html');
        if ($json === false && $html === false) {
            return true;
        }
        if ($json === false) {
            return false;
        }
        if ($html === false) {
            return true;
        }
        return $json < $html;
    }
}