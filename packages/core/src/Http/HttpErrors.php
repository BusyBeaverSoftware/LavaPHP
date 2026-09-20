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
 *
 * **The environment that renders the page comes from the request** when the
 * caller does not pass one. `App::handle()` records it as {@see ENV_ATTRIBUTE} on
 * every request, so `HttpErrors::forReport($input->report(), $request)` — the
 * pattern the docs show, in a handler that has no easy way to learn the
 * environment — renders the production page in production. With no environment
 * passed and none recorded, the page is the production one: the default that
 * shows less is the one that cannot put a submitted value in front of a browser.
 *
 * **In production, a server fault withholds everything but its code.** A 5xx
 * problem's context is where the internals are — the SQL and its bound values
 * for `query_failed`, an upstream's response body for `unexpected_status`, an
 * exception's message for `unexpected_failure` — and JSON is what any client
 * without an `Accept` header gets. The SENTENCE and the FIX are internals too,
 * which cost us a finding: `template_not_found` interpolates the absolute
 * template directory and the app's whole template inventory, `query_failed`
 * carries the driver's message, and a transport failure carries the upstream
 * URL. Withholding the context while sending the same facts in the sentence
 * beside it was a guard that looked like one. So a production 5xx keeps its
 * code, its severity and the shape a client parses, and sends a fixed sentence,
 * a fixed fix, `context` as `{}` and `source` as null ({@see redacts()}).
 *
 * The code is what a client can act on, and it is the one field built from a
 * constant rather than from the app. Redacting by default rather than per
 * problem is deliberate: a new problem class that forgot to redact would leak,
 * and a guard whose safe case is the one nobody wrote is not a guard.
 *
 * When the problem was thrown to `App` — from a handler, a middleware or a
 * subject resolver — `App` writes the whole problem, sentence included, to the
 * log. A 5xx a handler renders itself through this class is redacted the same
 * way and logged by nobody, because this class has no logger: throw the problem
 * rather than rendering it, or log it before calling this. A 4xx keeps
 * everything in every environment: it is the caller's mistake, and the field,
 * the rule and the fix are what let an agent repair its request in one round
 * trip.
 */
final class HttpErrors
{
    /** The request attribute `App::handle()` records the app's environment under. */
    public const ENV_ATTRIBUTE = 'lava.env';

    /**
     * What a redacted problem says instead of its own sentence and fix.
     *
     * Constants, and public, because the two media have to agree: JSON goes
     * through {@see redacted()} and the browser page through
     * {@see DiagnosticsPage}, and a production 500 that said one thing in one
     * medium and another in the other would send a reader looking for a
     * difference that is not there.
     */
    public const REDACTED_MESSAGE = 'The server could not handle this request.';
    public const REDACTED_FIX = 'Read the server log: the diagnosis, with the problem sentence, its context and its source, was written there.';

    public static function toResponse(LavaProblem $problem, ?ServerRequestInterface $request = null, ?string $env = null): ResponseInterface
    {
        $report = new ProblemReport();
        $report->add($problem);
        $response = self::reportToResponse($report, $problem->httpStatus(), $request, $env);
        if ($problem->code() === 'method_not_allowed') {
            // A 405 must say what IS allowed (RFC 9110 §15.5.5).
            $allowed = self::methodNames($problem->context['allowed'] ?? null);
            if ($allowed !== []) {
                $response = $response->withHeader('Allow', implode(', ', $allowed));
            }
        }
        return $response;
    }

    /**
     * The method names a 405 is allowed to advertise.
     *
     * The context is `array<string, mixed>` — a problem carries whatever the
     * code that raised it put there — so the value is checked rather than
     * assumed. A non-string is dropped instead of cast: a header can only carry
     * text, and `(string)` on an array would raise a TypeError from inside the
     * rendering of the very error meant to explain a bad request.
     *
     * @return list<string>
     */
    private static function methodNames(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
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
        ?string $env = null,
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
        ?string $env = null,
    ): ResponseInterface {
        $env ??= self::envOf($request);

        if (self::wantsJson($request)) {
            $problems = $report->json();
            if (self::redacts($status, $env)) {
                $problems = array_map(self::redacted(...), $problems);
            }

            // Invalid UTF-8 is substituted, not thrown: a problem carries
            // whatever text the failure had — an upload's name, a latin-1
            // column — and an exception from encoding it would replace the
            // diagnosis. The HTML page and the logger already substitute.
            return Responses::text(
                json_encode(
                    ['problems' => $problems],
                    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
                ),
                $status,
            )->withHeader('Content-Type', 'application/json');
        }
        // The status goes with it: the page renders the sentence and the fix
        // whatever `$env` is, so without the status it would print in prod what
        // the JSON beside it withholds.
        return Responses::html(DiagnosticsPage::render($report, $env, $status), $status);
    }

    /**
     * Whether a response at this status, in this environment, withholds the
     * problems' context and source from the client.
     *
     * Public because the decision has two consumers that must agree: this class
     * leaves the details out of the response, and `App` writes them to the log —
     * a detail withheld from one and missing from the other would make a
     * production 500 undiagnosable.
     */
    public static function redacts(int $status, string $env): bool
    {
        return $env === 'prod' && $status >= 500;
    }

    /**
     * A problem object with everything but its code withheld. The keys stay, so
     * the shape a client parses is the same in every environment: `context` as
     * an empty JSON object (an empty PHP array would encode as a list) and
     * `source` as null.
     *
     * @param array<string, mixed> $problem
     * @return array<string, mixed>
     */
    private static function redacted(array $problem): array
    {
        $problem['problem'] = self::REDACTED_MESSAGE;
        $problem['fix'] = self::REDACTED_FIX;
        $problem['context'] = new \stdClass();
        $problem['source'] = null;

        return $problem;
    }

    /** The environment the request was answered in, or `prod` when nothing recorded one. */
    private static function envOf(?ServerRequestInterface $request): string
    {
        $env = $request?->getAttribute(self::ENV_ATTRIBUTE);

        return is_string($env) && $env !== '' ? $env : 'prod';
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