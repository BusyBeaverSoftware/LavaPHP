<?php

declare(strict_types=1);

namespace Lava\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The one place a Twig `Environment` is configured, so that no app has to
 * decide — and no app can accidentally decide differently.
 *
 * Twig's defaults are chosen for a library that must not surprise anyone. Two
 * of them are wrong for a framework whose first pillar is that a mistake should
 * be loud, and this class overrides exactly those two and nothing else:
 *
 *  - **autoescape is on, always, and is not configurable.** Twig's own default
 *    is `false` unless the file ends in `.html.twig`, which means the safety of
 *    a page depends on its filename. A template rendered as `page.twig` is
 *    unescaped; the same template renamed `page.html.twig` is escaped. An
 *    agent writing a template cannot be asked to know that, and the failure
 *    mode of getting it wrong is an XSS hole that no test would catch. Escaping
 *    is opt-out per value (`{{ html|raw }}`), which is the correct direction:
 *    the dangerous thing is the one you have to type.
 *  - **strict_variables is on.** With it off, `{{ titel }}` renders as the empty
 *    string and the page looks fine — the bug ships. With it on, the render
 *    fails with the variable's name and the line, and the app's own error page
 *    explains it. A blank where a value should be is the single hardest defect
 *    to notice in a template, and this makes it impossible to have.
 *
 * Everything else stays at Twig's default on purpose. In particular `debug` is
 * passed through rather than forced on: Twig's debug mode adds the `dump()`
 * function and `{% dump %}` tag, which are development tools, and the pack's
 * job is to wire the app's environment state into Twig rather than to have an
 * opinion of its own.
 *
 * @internal how the pack builds Twig; an app takes ViewRenderer and configures it through config/view.php
 */
final class TwigFactory
{
    /**
     * @param string $templateDir absolute path to the templates
     * @param string $cacheDir absolute path for compiled templates, or '' for none
     * @param bool $debug whether Twig's own debug tools are on
     * @param array<string, list<string>> $namespaces Twig namespace => absolute directories, searched in order
     */
    public static function of(string $templateDir, string $cacheDir, bool $debug, array $namespaces = []): Environment
    {
        $loader = new FilesystemLoader($templateDir);
        foreach ($namespaces as $namespace => $dirs) {
            foreach ($dirs as $dir) {
                $loader->addPath($dir, $namespace);
            }
        }

        $environment = new Environment(
            $loader,
            [
                'autoescape' => 'html',
                'strict_variables' => true,
                'debug' => $debug,
                // Cache OFF in debug, whatever the app configured. A compiled
                // template is not recompiled when the source changes unless
                // Twig's own staleness check runs, and that check is exactly
                // what `debug: false` disables. The result is the worst kind of
                // confusing: you edit the template, the page does not change,
                // and nothing says why. Paying recompilation in debug is the
                // right trade, and production keeps the cache.
                'cache' => $debug ? false : ($cacheDir === '' ? false : $cacheDir),
            ],
        );

        return $environment;
    }
}
