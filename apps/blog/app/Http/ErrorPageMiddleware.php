<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use Lava\Core\Problem\LavaProblem;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders a thrown problem as one of *this blog's* pages, when the client is a
 * browser.
 *
 * Out of the box, a problem that escapes every layer is rendered by
 * `HttpErrors::toResponse()`, which negotiates on `Accept`: `curl` and agents
 * get the `{"problems": […]}` envelope, and a browser gets the framework's
 * diagnostics page, headed "LavaPHP found 1 problem", with the problem code,
 * severity and context as a definition list. That page is correct and genuinely
 * useful — to a developer. To a reader of this blog it is a stack of internals
 * with the site's name missing. The renderer is a static call, so the seam is a
 * layer: a global middleware meets every problem thrown inward of it and can
 * answer first.
 *
 * "Every" includes the requests no route answers. `lava/core` throws an unknown
 * path, a known path under the wrong method, and a body that does not parse
 * through the global middleware as well, so `GET /no/such/page` gets the same
 * page as `GET /posts/999`.
 *
 * Three rules keep this from swallowing things it should not, or dropping what
 * the framework's own renderer would have said.
 *
 * **It only handles HTML.** A request that did not ask for HTML is re-thrown
 * untouched, so `/api/*` still answers with its envelope and `curl` still gets
 * the diagnostics an agent wants. This middleware narrows nothing; it adds a
 * page for the one audience the default renderer cannot serve well.
 *
 * **It only handles 4xx.** A 5xx is a bug in this app, and a bug is exactly the
 * case where the framework's page — file, line, failing input — is the more
 * useful answer, so those are re-thrown too.
 *
 * **A 405 keeps its `Allow` header.** The framework's renderer adds it (RFC 9110
 * §15.5.5), so a page rendered here instead has to add it as well, or the
 * response stops saying which methods would have worked.
 *
 * Global, and listed *last* in `app/Middleware.php` — innermost. It has to be
 * inside `SessionMiddleware` (the layout's sign-out form reads the CSRF token,
 * and a PSR-7 request only carries the session attribute in the frames the
 * session middleware passes it down to) and outside everything else (it is only
 * useful if nothing that can throw sits outside it).
 */
final class ErrorPageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly Auth $auth,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (LavaProblem $problem) {
            if (!$this->wantsHtml($request) || $problem->httpStatus() >= 500) {
                throw $problem;
            }

            return $this->page($problem, $request);
        }
    }

    private function page(LavaProblem $problem, ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->view->renderStatus('error.twig', $problem->httpStatus(), Page::context(
            $this->auth,
            $request,
            [
                'status' => $problem->httpStatus(),
                'heading' => self::heading($problem->httpStatus()),
                // The `problem` text, not the `fix`: the fix is addressed to
                // whoever maintains this app ("Edit one of your own posts
                // instead: GET / …") and reads as nonsense to a visitor. The
                // same split `FormErrors::single()` makes, for the same reason.
                'message' => $problem->getMessage(),
            ],
        ));

        $allowed = self::allowed($problem);

        return $allowed === [] ? $response : $response->withHeader('Allow', implode(', ', $allowed));
    }

    /**
     * The methods a 405 names, read the way the framework's renderer reads them:
     * from `context.allowed`, strings only, and nothing for any other problem.
     *
     * @return list<string>
     */
    private static function allowed(LavaProblem $problem): array
    {
        $allowed = $problem->context['allowed'] ?? null;
        if ($problem->code() !== 'method_not_allowed' || !is_array($allowed)) {
            return [];
        }

        $methods = [];
        foreach ($allowed as $method) {
            if (is_string($method) && $method !== '') {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * The same positional test `HttpErrors::wantsJson()` uses, inverted, so the
     * two renderers agree about who is a browser. A header naming neither media
     * is not HTML: that is `curl`, and an agent asking for this route would
     * rather have the envelope than a web page.
     */
    private function wantsHtml(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        $json = strpos($accept, 'json');
        $html = strpos($accept, 'html');

        if ($html === false) {
            return false;
        }
        if ($json === false) {
            return true;
        }

        return $html < $json;
    }

    /**
     * What a reader is told the situation was. The problem's own code stays out
     * of it — `post_not_found` is a fact about this app's error taxonomy, not
     * something a visitor can do anything with.
     */
    private static function heading(int $status): string
    {
        return match ($status) {
            400 => 'That request did not make sense',
            401 => 'Please sign in',
            403 => 'Not yours to change',
            404 => 'Not found',
            405 => 'That method is not allowed here',
            409 => 'That is already taken',
            422 => 'Something in that form needs fixing',
            default => 'Something went wrong',
        };
    }
}
