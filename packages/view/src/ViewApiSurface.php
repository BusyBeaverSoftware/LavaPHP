<?php

declare(strict_types=1);

namespace Lava\View;

use Lava\Core\Map\ApiSurface;

/** `lavaphp/view`'s public surface: the renderer an app takes, and what it can be asked. */
final class ViewApiSurface extends ApiSurface
{
    public function pack(): string
    {
        return 'view';
    }

    public function package(): string
    {
        return 'lavaphp/view';
    }

    public function feature(): string
    {
        // `views`, not `view`: the pack is lavaphp/view and its gate is plural.
        return 'views';
    }

    public function namespacePrefix(): string
    {
        return 'Lava\\View\\';
    }

    public function sourceRoot(): string
    {
        return __DIR__;
    }

    public function groups(): array
    {
        return [
            '(root)' => 'the renderer, the template functions it installs, and the module',
        ];
    }

    public function exclusions(): array
    {
        return [
            'Problem/' => 'every problem is catalogued in docs/problem-codes.md, under its own drift guard',
        ];
    }

    public function examples(): array
    {
        return [
            \Lava\View\ViewRenderer::class => <<<'PHP'
                use Lava\View\ViewRenderer;
                use Psr\Http\Message\ResponseInterface;

                function show(ViewRenderer $view): ResponseInterface
                {
                    // A rendered template IS the response, so a handler returns it
                    // directly; renderToString() is for a mail body or a fragment.
                    return $view->render('posts/show.twig', ['title' => 'Hello']);
                }

                function themes(ViewRenderer $view): array
                {
                    // The namespaces config/view.php declares, so an app does not
                    // keep a second list of its themes.
                    return $view->exists('@admin/dashboard.twig') ? $view->namespaces() : [];
                }
                PHP,
        ];
    }
}
