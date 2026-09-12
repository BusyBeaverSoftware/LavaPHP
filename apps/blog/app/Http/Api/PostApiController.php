<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Auth\Auth;
use App\Blog\Post;
use App\Blog\PostRepository;
use App\Http\PostForm;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The same posts, as JSON — and the reason this is a separate class from
 * {@see \App\Http\BlogController} rather than a flag on it.
 *
 * LavaPHP has no content negotiation: a route answers one media, chosen when it
 * is registered. So `/api/posts` is a different route with a different gate
 * ({@see \App\Http\RequireLoginJsonMiddleware} returns a 401 envelope where the
 * HTML routes redirect), and a different failure shape (`forReport()` where the
 * HTML routes re-render a form). One handler branching on `Accept` would need
 * all three differences in `if`s, and would still be wrong for a caller that
 * sends no `Accept` at all.
 *
 * The duplication between the two classes is real and is the price of that
 * choice. It is bounded because the two things that must not diverge — the
 * validation rules and the form-to-value mapping — are in `PostForm`, shared.
 */
final class PostApiController
{
    public function index(
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
    ): ResponseInterface {
        return Responses::json([
            'posts' => array_map(
                static fn (Post $post): array => $post->json(),
                $posts->visibleTo($auth->user($request)),
            ),
        ]);
    }

    public function store(
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
    ): ResponseInterface {
        $user = $auth->requireUser($request);
        $form = self::form($request);

        // Thrown, not caught: App::handle() turns a LavaProblem from a handler
        // into the response its own `httpStatus()` asks for, so a client that
        // sent no token gets `csrf_mismatch` with a 403 and the fix text.
        $auth->assertCsrf($request, $form);

        $input = PostForm::validator()->validate($form);

        if ($input->failed()) {
            return HttpErrors::forReport($input->report(), $request);
        }

        $post = $posts->create(
            $user->id,
            PostForm::title($input),
            PostForm::body($input),
            PostForm::published($input),
        );

        return Responses::json(['post' => $post->json()], 201);
    }

    /** @return array<string, mixed> */
    private static function form(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
