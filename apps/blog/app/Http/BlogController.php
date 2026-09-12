<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Blog\Post;
use App\Blog\PostRepository;
use App\Problem\CsrfMismatch;
use App\Problem\NotPostAuthor;
use App\Problem\PostNotFound;
use Lava\Core\Http\Responses;
use Lava\Core\Routing\RouteArgs;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Creating, changing and removing posts. The write half of {@see PostPageController}.
 *
 * Three handler shapes are worth noticing here, because each is a decision the
 * framework forced rather than a style choice:
 *
 *  - **Authorisation is not a middleware.** `RequireLoginMiddleware` answers
 *    "is anybody signed in", which is a property of the request; "is this the
 *    author" needs the row, and the row is fetched here. Splitting them is what
 *    keeps the middleware reusable and this check impossible to forget in the
 *    one place it matters — `visibleById()` returning the post and
 *    `isOwnedBy()` rejecting it are always adjacent.
 *
 *  - **`store` and `update` re-render the editor on failure**, rather than
 *    returning a problem envelope. The route answers HTML, so HTML has to be the
 *    answer even when the input was bad.
 *
 *  - **`destroy` returns the envelope**, which is the one place in this app
 *    where a 403 problem reaches a browser. A delete button has no fields to
 *    correct, so there is no form to re-render and no text to preserve — the
 *    machine-readable answer is simply the right one. It is also the least
 *    likely of the three to be triggered by a real person.
 */
final class BlogController
{
    public function store(
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        $user = $auth->requireUser($request);
        $form = self::form($request);

        try {
            $auth->assertCsrf($request, $form);
        } catch (CsrfMismatch $problem) {
            return $this->editor($view, $auth, $request, null, 403, FormErrors::single($problem), $form);
        }

        $input = PostForm::validator()->validate($form);

        if ($input->failed()) {
            return $this->editor($view, $auth, $request, null, 422, FormErrors::collect($input->problems()), $form);
        }

        $post = $posts->create(
            $user->id,
            PostForm::title($input),
            PostForm::body($input),
            PostForm::published($input),
        );

        return Responses::redirect('/posts/' . $post->id, 303);
    }

    public function update(
        RouteArgs $args,
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        $user = $auth->requireUser($request);
        $id = $args->int('id');

        // Throws PostNotFound or NotPostAuthor before anything is read from the
        // form, so a request aimed at somebody else's post cannot learn anything
        // by the shape of the failure.
        $post = $this->owned($id, $request, $posts, $auth, $user);

        $form = self::form($request);

        try {
            $auth->assertCsrf($request, $form);
        } catch (CsrfMismatch $problem) {
            return $this->editor($view, $auth, $request, $post, 403, FormErrors::single($problem), $form);
        }

        $input = PostForm::validator()->validate($form);

        if ($input->failed()) {
            return $this->editor($view, $auth, $request, $post, 422, FormErrors::collect($input->problems()), $form);
        }

        $posts->update($id, PostForm::title($input), PostForm::body($input), PostForm::published($input));

        return Responses::redirect('/posts/' . $id, 303);
    }

    public function destroy(
        RouteArgs $args,
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
    ): ResponseInterface {
        $user = $auth->requireUser($request);
        $id = $args->int('id');

        // Checked before the lookup: a forged request should not be able to learn
        // whether a post exists by comparing a 403 with a 404.
        $auth->assertCsrf($request, self::form($request));

        // Throws PostNotFound or NotPostAuthor; the pipeline turns either into
        // the right response for whoever asked.
        $this->owned($id, $request, $posts, $auth, $user);

        $posts->delete($id);

        return Responses::redirect('/', 303);
    }

    /**
     * The post, if this user may change it — otherwise a thrown problem.
     *
     * This used to return `Post|ResponseInterface`, on the reasoning that a 404
     * and a 403 are ordinary answers rather than exceptional ones. That
     * reasoning does not survive contact with the framework: `App::handle()`
     * catches `LavaProblem` and renders it at `httpStatus()`, so throwing *is*
     * the ordinary path — and returning a pre-rendered response instead made two
     * things worse. It froze the media: `HttpErrors::toResponse()` had already
     * chosen JSON by the time the response came back up, so a browser hitting a
     * dead link got an envelope. And it made the failure invisible to
     * `ErrorPageMiddleware`, which can only see what is thrown. The union was a
     * workaround for an exception path this framework already has.
     */
    private function owned(
        int $id,
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
        \App\Auth\User $user,
    ): Post {
        $post = $posts->visibleById($id, $user);

        if ($post === null) {
            throw PostNotFound::of($id);
        }

        if (!$post->isOwnedBy($user->id)) {
            throw NotPostAuthor::of($id, $user->id);
        }

        return $post;
    }

    /**
     * @param array<string, string> $errors field name => message, as {@see FormErrors} builds them
     * @param array<string, mixed> $form
     */
    private function editor(
        ViewRenderer $view,
        Auth $auth,
        ServerRequestInterface $request,
        ?Post $post,
        int $status,
        array $errors,
        array $form,
    ): ResponseInterface {
        return $view->renderStatus('editor', $status, Page::context($auth, $request, [
            'post' => $post,
            'values' => PostForm::fromForm($form),
            'errors' => $errors,
        ]));
    }

    /** @return array<string, mixed> */
    private static function form(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
