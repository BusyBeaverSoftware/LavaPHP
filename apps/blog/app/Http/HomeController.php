<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Blog\PostRepository;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The public front page: every post the caller may see.
 *
 * There is no `if (signed in)` branch here, and that is the point — the
 * visibility rule lives in `PostRepository::visibleTo()`, so an anonymous
 * visitor and an author with drafts run the same handler and the same template.
 * A handler that fetched "published posts" and then fetched "my drafts" would be
 * two places for the rule to disagree with itself.
 */
final class HomeController
{
    public function index(
        ServerRequestInterface $request,
        PostRepository $posts,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        return $view->render('home', Page::context($auth, $request, [
            'posts' => $posts->visibleTo($auth->user($request)),
        ]));
    }
}
