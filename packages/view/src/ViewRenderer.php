<?php

declare(strict_types=1);

namespace Lava\View;

use Lava\Core\Http\Responses;
use Lava\Core\Problem\LavaProblem;
use Lava\View\Problem\TemplateFailed;
use Lava\View\Problem\TemplateNotFound;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

/**
 * Renders a template into a response — the service a handler type-hints.
 *
 * The return type is a `ResponseInterface` rather than a string, and that is
 * the design decision worth explaining. Twig returns HTML; a handler must
 * return a response. Somewhere between the two the string has to be wrapped,
 * and the options are: the handler wraps it (`Responses::html($view->render(…))`
 * at every call site), or the renderer does. The renderer does, because the
 * second form makes the whole thing one expression in a handler —
 *
 *     return $view->render('tasks/show', ['task' => $task]);
 *
 * — and because `renderStatus()` then has an obvious home for the case that
 * always appears next: rendering the same template with a 404 or a 422. A
 * renderer that returned a string would leave every app to reinvent that, and
 * every app would get the header and the status slightly differently.
 *
 * **Every Twig failure leaves here as a `LavaProblem`.** That is the pack's
 * whole reason for existing rather than shipping a `TwigFactory` and a README:
 * a template typo is a framework failure like any other, and it must arrive
 * with a file, a line, and a fix. See {@see TemplateFailed}.
 */
final class ViewRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly string $templateDir,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): ResponseInterface
    {
        return Responses::html($this->renderToString($template, $context));
    }

    /**
     * The same render, with a status — a 404 page, a 422 form re-render.
     *
     * @param array<string, mixed> $context
     */
    public function renderStatus(string $template, int $status, array $context = []): ResponseInterface
    {
        return Responses::html($this->renderToString($template, $context), $status);
    }

    /**
     * The HTML alone, for the rare caller that is not building a response —
     * an email body, a fragment written to a file.
     *
     * @param array<string, mixed> $context
     */
    public function renderToString(string $template, array $context = []): string
    {
        $template = self::normalize($template);

        if (!$this->exists($template)) {
            throw $this->notFound($template);
        }

        try {
            return $this->twig->render($template, $context);
        } catch (SyntaxError $error) {
            throw TemplateFailed::syntax($template, $error);
        } catch (RuntimeError $error) {
            // Twig wraps whatever a template function throws in a RuntimeError
            // whose `previous` is the original. A LavaProblem raised by url()
            // or feature() is already a precise diagnosis with its own fix, so
            // it passes through UNTOUCHED — the same rule lavaphp/db's
            // MigrationFailed follows. Wrapping it would turn a `bad_view_call`
            // into a `template_failed` and bury the fix inside Twig's sentence
            // ("An exception has been thrown during the rendering of a
            // template (\"url(…) was given null for param 'id'…\")"), which is
            // the specific, actionable half of the message hidden behind the
            // generic one.
            throw self::raised($error) ?? TemplateFailed::runtime($template, $error);
        }
    }

    /**
     * The framework problem a Twig error is carrying, if it is carrying one.
     *
     * Walks the whole chain rather than checking one level: Twig's own wrapping
     * is one deep today, and a template function that calls another template
     * (or a future Twig version that nests differently) would put it deeper.
     * Bounded, because a throwable chain with a cycle is not something to hang
     * on.
     */
    private static function raised(RuntimeError $error): ?LavaProblem
    {
        $cause = $error->getPrevious();
        for ($depth = 0; $cause !== null && $depth < 8; $depth++) {
            if ($cause instanceof LavaProblem) {
                return $cause;
            }
            $cause = $cause->getPrevious();
        }

        return null;
    }

    /**
     * The problem for a template that is not there, naming where it was looked for.
     *
     * A `@namespace/…` name is looked up in that namespace's directories — a
     * theme added through `environment()->getLoader()->addPath($dir, 'theme')` —
     * not in the pack's own. So its directories and its list come from Twig's
     * loader: listing the main directory for it sent the reader to the wrong
     * place (Lava Notes, R2-B15).
     */
    private function notFound(string $template): TemplateNotFound
    {
        $loader = $this->twig->getLoader();
        $slash = strpos($template, '/');
        if (!str_starts_with($template, '@') || $slash === false || !$loader instanceof FilesystemLoader) {
            return TemplateNotFound::of($template, $this->templateDir, self::templatesIn($this->templateDir));
        }

        $namespace = substr($template, 1, $slash - 1);
        $paths = $loader->getPaths($namespace);
        $available = [];
        foreach ($paths as $path) {
            foreach (self::templatesIn($path) as $name) {
                $available[] = "@{$namespace}/{$name}";
            }
        }
        $available = array_values(array_unique($available));
        sort($available);

        return TemplateNotFound::inNamespace($template, $namespace, substr($template, $slash + 1), $paths, $available);
    }

    /**
     * Whether a template exists — so a handler can choose between rendering a
     * page and returning a 404 without catching an exception to find out.
     */
    public function exists(string $template): bool
    {
        return $this->twig->getLoader()->exists(self::normalize($template));
    }

    /**
     * A template name, with the pack's own extension.
     *
     * `render('tasks/show')` is the spelling, and `.twig` is optional — which is
     * one rule, not two ways to do a thing. Twig itself has no default
     * extension and looks for exactly the name it is given, so without this the
     * pack would accept `'tasks/show.twig'` and reject `'tasks/show'` — and the
     * `template_not_found` message would have to list names that are not the
     * names its own reader is expected to type. Normalizing here means the
     * message's list and the accepted spelling are the same string.
     *
     * It also matches the convention the rest of the framework already uses for
     * a file a package owns: `PackInfo` names a config file as `'database'` and
     * the loader supplies `.php`. The pack owns `.twig` the same way.
     */
    private static function normalize(string $template): string
    {
        return str_ends_with($template, '.twig') ? $template : $template . '.twig';
    }

    /**
     * The directory templates are read from. Exposed because a problem message
     * has to name it, and because "where does this app keep its templates?" is
     * a question `lava describe` should be able to answer.
     */
    public function templateDir(): string
    {
        return $this->templateDir;
    }

    /**
     * The Twig environment, for an app that needs to add a filter of its own.
     *
     * The renderer is a singleton, so what is added here holds for every render
     * the process performs. A constant is safe to add as a global; request state
     * is not — a global holding the signed-in user would still hold it for the
     * next visitor a long-running worker serves. Pass request state in the
     * render context instead.
     *
     * **Add filters, functions, globals and extensions before the first render.**
     * Twig locks its extension set the first time it compiles or renders, and a
     * later `addFilter()` throws `LogicException: Unable to add filter … as
     * extensions have already been initialized`. Code that may run after a render
     * — a handler, a middleware — guards the addition with the extension it lives
     * in: `if (!$twig->hasExtension(AppExtension::class)) { $twig->addExtension(new AppExtension()); }`.
     */
    public function environment(): Environment
    {
        return $this->twig;
    }

    /**
     * Every template the directory holds, as renderable names.
     *
     * Walked here rather than asked of Twig: `FilesystemLoader` can answer
     * `exists()` but not "list", and a pack that grew its own loader to add
     * that would be inventing an API Twig does not have. The names are the
     * paths relative to the template directory, WITH the extension —
     * `page.twig`, `partials/_nav.twig`.
     *
     * With the extension, not without, because these names go into a problem
     * message that is read by someone about to type one of them, and the
     * message's own subject line names the file it looked for (`No template
     * 'does/not/exist.twig'`). A list that dropped the extension would spell
     * the same file two ways in one message. `render()` accepts the extension
     * as written — {@see normalize()} only adds it when it is missing — so
     * every name in this list can be pasted straight back in.
     *
     * Bounded at 500 entries: this feeds a problem message, and an app with a
     * pathological template directory should get a truncated list rather than
     * a slow boot. Sorted, so the message is stable across filesystems and two
     * identical failures produce identical text.
     *
     * @return list<string>
     */
    private static function templatesIn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($walk as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!str_ends_with($path, '.twig')) {
                continue;
            }
            $found[] = substr($path, strlen($directory) + 1);
            if (count($found) >= 500) {
                break;
            }
        }

        sort($found);

        return $found;
    }
}
