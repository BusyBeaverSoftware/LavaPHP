<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Blog\PostRepository;
use App\Problem\NotPostAuthor;
use App\Problem\PostNotFound;
use Lava\Core\Routing\RouteArgs;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTML pages for one post: read it, and the two forms that change it.
 *
 * The read path and the write paths answer the same question differently, and
 * the difference is deliberate. `show()` throws a **404** for a draft belonging
 * to somebody else, because confirming that a post exists but is not yours tells
 * an anonymous caller which ids are real. `edit()` throws a **403**, because by
 * the time it runs the caller is signed in and the middleware has already let
 * them through — hiding it now would be confusing rather than protective.
 *
 * Both are thrown rather than returned as responses, and the difference is not
 * stylistic: `App::handle()` catches a `LavaProblem` and renders it at its own
 * `httpStatus()`, choosing the media from the request's `Accept`. A controller
 * that builds its own response has to pick a media itself, and picking JSON is
 * how a browser ends up staring at an envelope. Throwing also lets
 * `ErrorPageMiddleware` see the failure and give a visitor one of this app's
 * pages instead of the framework's diagnostics.
 */
final class PostPageController
{
    public function show(
        RouteArgs $args,
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        $id = $args->int('id');
        $post = $posts->visibleById($id, $auth->user($request));

        if ($post === null) {
            throw PostNotFound::of($id);
        }

        return $view->render('post', Page::context($auth, $request, ['post' => $post]));
    }

    /** The empty editor. Behind RequireLoginMiddleware, so it renders unguarded. */
    public function blank(
        ServerRequestInterface $request,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        return $view->render('editor', Page::context($auth, $request, [
            'post' => null,
            'values' => PostForm::BLANK,
            'errors' => [],
        ]));
    }

    public function edit(
        RouteArgs $args,
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        $id = $args->int('id');
        $user = $auth->requireUser($request);

        // `visibleById()` already excludes posts this user may not read, so a
        // post that comes back and is not theirs is one that is published —
        // which is exactly the case worth a 403 rather than a 404.
        $post = $posts->visibleById($id, $user);

        if ($post === null) {
            throw PostNotFound::of($id);
        }

        if (!$post->isOwnedBy($user->id)) {
            throw NotPostAuthor::of($id, $user->id);
        }

        return $view->render('editor', Page::context($auth, $request, [
            'post' => $post,
            'values' => PostForm::fromPost($post),
            'errors' => [],
        ]));
    }
}
