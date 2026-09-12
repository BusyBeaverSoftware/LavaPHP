<?php

declare(strict_types=1);

namespace App\Upstream;

use Lava\HttpClient\HttpClient;

/**
 * This app's outbound call, as one service.
 *
 * The base URL arrives as a constructor argument rather than being read here,
 * because a handler's parameters are injected by TYPE — the request, `RouteArgs`,
 * or a registered service — and a `string` is none of those. So
 * `app/Services.php` reads `app.upstream` once and hands it over, exactly the
 * way `TaskRepository` is handed a `Connection`. That keeps every dependency of
 * this class visible in the one file that wires it, and keeps the value out of
 * the request path: it is decided at boot, not on the request that happens to
 * arrive first.
 *
 * The URL is a value from config, never one from the request. An endpoint that
 * fetched a caller-supplied URL would be an SSRF primitive — the reason
 * `lavaphp/http-client` refuses `file://` and `gopher://` in the first place — and
 * a demo that shipped one would be teaching the wrong thing.
 *
 * Nothing here catches a failure. `getJson()` raises a `LavaProblem` —
 * `unexpected_status`, `transport_failed`, `bad_json_response` — and
 * `App::handle()` already turns any of those into the app's own error envelope,
 * at the status the problem asks for. Catching it here would mean re-deciding
 * something the pack has already decided, and would put a second, worse
 * description of the failure in front of the first.
 */
final class Upstream
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * The upstream's own liveness answer, decoded.
     *
     * `getJson()` requires a 2xx and a body that decodes to an object or array,
     * so the three ways this can go wrong are three different problem codes with
     * three different fixes — not one "request failed".
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->http->getJson(rtrim($this->baseUrl, '/') . '/health');
    }
}
