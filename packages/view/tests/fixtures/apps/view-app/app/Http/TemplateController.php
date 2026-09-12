<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Routing\RouteArgs;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;

/**
 * Every way a template reaches a response, and every way it can fail.
 *
 * The handler contract again: no constructor, dependencies as typed method
 * parameters. `ViewRenderer` is a registered container id (lavaphp/view registers
 * it), so it is injectable by type like any other service — which is the point
 * of the pack registering one id rather than shipping static helpers.
 */
final class TemplateController
{
    public function page(RouteArgs $args, ViewRenderer $view): ResponseInterface
    {
        // The title contains HTML on purpose: the escaping test is only a test
        // if the value would be dangerous unescaped.
        return $view->render('page', [
            'title' => '<script>alert(1)</script>',
            'id' => $args->has('id') ? $args->int('id') : 7,
        ]);
    }

    public function gated(ViewRenderer $view): ResponseInterface
    {
        return $view->render('gated');
    }

    public function badUrl(ViewRenderer $view): ResponseInterface
    {
        return $view->render('bad-url', ['user' => new \stdClass()]);
    }

    /**
     * The null case, which is the one that actually happens: a template links
     * to a record's page and the record was never loaded, so `user_id` is null
     * rather than missing. Distinct from {@see badUrl()} because the fix is
     * different — guard the link, do not change the field.
     */
    public function badParam(ViewRenderer $view): ResponseInterface
    {
        return $view->render('bad-param', ['user_id' => null]);
    }

    public function missing(ViewRenderer $view): ResponseInterface
    {
        return $view->render('does/not/exist');
    }

    public function broken(ViewRenderer $view): ResponseInterface
    {
        return $view->render('broken');
    }

    public function untyped(ViewRenderer $view): ResponseInterface
    {
        // No context at all: strict_variables turns the template's missing
        // variable into a problem rather than a blank page.
        return $view->render('untyped');
    }

    public function status(ViewRenderer $view): ResponseInterface
    {
        return $view->renderStatus('page', 404, ['title' => 'gone', 'id' => 1]);
    }
}
