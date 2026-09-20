<?php

declare(strict_types=1);

namespace Lava\View\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * Something turned Twig's default escaping strategy off, and a render was about
 * to go out unescaped.
 *
 * The pack's headline promise is that autoescaping is on, always, and is not
 * configurable — {@see \Lava\View\TwigFactory}. It is not configurable through
 * `config/view.php`, but it was one line away through the accessor the pack's
 * own documentation recommends for adding a filter (security review, F2):
 *
 * ```php
 * $view->environment()->getExtension(EscaperExtension::class)->setDefaultStrategy(false);
 * ```
 *
 * The renderer is a singleton, so that line unescapes every template the
 * process renders afterwards, including pages written by someone who read the
 * guarantee. An XSS hole no test would catch is exactly what the guarantee
 * exists to prevent, so the render stops here instead.
 *
 * `{% autoescape false %}` inside a template is untouched by this: it is
 * lexically scoped to the template that writes it, which is the deliberate
 * opt-out the pack documents for plain-text bodies.
 */
final class AutoescapeDisabled extends LavaProblem
{
    /**
     * @param string $template the name as looked up, extension included
     * @param string $strategy what the escaper answers instead of `html`
     */
    public static function of(string $template, string $strategy): self
    {
        return new self(
            "Twig's default escaping strategy is {$strategy}, not 'html', so rendering '{$template}' would send unescaped values to the browser.",
            "Remove the setDefaultStrategy() call — it is usually a line through ViewRenderer::environment() copied from a "
            . 'Twig tutorial. This pack fixes the strategy at html on purpose: escaping is opt-out per value '
            . "({{ html|raw }}), and for a whole plain-text template it is {% autoescape false %} inside that template, "
            . 'which changes nothing for any other.',
            ['template' => $template, 'strategy' => $strategy],
        );
    }

    public function code(): string
    {
        return 'autoescape_disabled';
    }
}
