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
        $response = self::reportToResponse($report, $problem->httpStatus(), $request, $env);
        if ($problem->code() === 'method_not_allowed'
            && isset($problem->context['allowed'])
            && is_array($problem->context['allowed'])) {
            // A 405 must say what IS allowed (RFC 9110 §15.5.5).
            $response = $response->withHeader('Allow', implode(', ', $problem->context['allowed']));
        }
        return $response;
    }

    /**
     * A whole report as one response, at the status its problems ask for.
     *
     * The multi-problem case is a validation failure: every bad field is its
     * own problem, and an agent needs all of them at once — fixing one field
     * per round trip is the thing this framework exists to avoid. The status
     * comes from the first problem, because a report is one response and
     * problems that disagree about their status agree about being the
     * caller's fault: `validation_failed` is 422 whichever field it names.
     *
     * An empty report is a programming error — there is nothing to render and
     * no status to pick — so it renders as a 500 saying exactly that, rather
     * than as a 200 with no body.
     */
    public static function forReport(
        ProblemReport $report,
        ?ServerRequestInterface $request = null,
        string $env = 'dev',
    ): ResponseInterface {
        $first = $report->problems()[0] ?? null;
        if (!$first instanceof LavaProblem) {
            return self::reportToResponse(
                $report,
                500,
                $request,
                $env,
            );
        }
        return self::reportToResponse($report, $first->httpStatus(), $request, $env);
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