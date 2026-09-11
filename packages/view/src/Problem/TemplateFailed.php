<?php

declare(strict_types=1);

namespace Lava\View\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;
use Twig\Error\Error;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * A template threw — and this is the whole reason the view pack wraps Twig at
 * all rather than handing it out raw.
 *
 * Twig's own exceptions carry the template name and the line, and they are
 * fine messages for a person reading a stack trace. They are the wrong shape
 * for this framework's contract, which says every framework failure is a
 * `LavaProblem` with a `file:line` an agent can open, the failing input, and an
 * imperative fix. An uncaught `Twig\Error\SyntaxError` becomes
 * `unexpected_failure` — "a Twig error escaped" — which throws away the line
 * Twig had already computed and tells the reader nothing they can act on.
 *
 * **Compile and run are separate factories** because they need different
 * fixes, and the difference is not cosmetic. A syntax error is a typo in the
 * template text and is fixed by editing it. A runtime error is usually not
 * about the template at all: with `strict_variables` on, the most common one
 * by far is a variable the template expects and the handler never passed — so
 * the fix names the handler, and saying "fix your template" would send the
 * reader to the wrong file.
 */
final class TemplateFailed extends LavaProblem
{
    /**
     * @param string $template the name as looked up, extension included — the renderer normalizes it before asking
     */
    public static function syntax(string $template, SyntaxError $error): self
    {
        return new self(
            "Template '{$template}' does not compile: {$error->getRawMessage()}",
            'Fix ' . self::where($template, $error) . '. Twig reports the first error only — expect to re-render '
            . 'after each fix, and run `lava serve` rather than `lava check` while iterating: a template is compiled '
            . 'when it is rendered, so nothing checks it before the request that uses it.',
            ['template' => $template, 'error' => $error::class, 'line' => $error->getTemplateLine()],
            self::source($template, $error),
            $error,
        );
    }

    /**
     * @param string $template the name as looked up, extension included — the renderer normalizes it before asking
     */
    public static function runtime(string $template, RuntimeError $error): self
    {
        return new self(
            "Template '{$template}' failed while rendering: {$error->getRawMessage()}",
            'Check the context passed to render() in the handler that renders this template — with strict_variables '
            . 'on, an undefined variable is an error rather than a blank, which is what turns a typo into this message '
            . 'instead of a silently empty page. If the variable is meant to be optional, give the template a default: '
            . '{{ ' . self::guessVariable($error) . '|default(\'\') }}.',
            ['template' => $template, 'error' => $error::class, 'line' => $error->getTemplateLine()],
            self::source($template, $error),
            $error,
        );
    }

    /**
     * The template file and line Twig already knows about.
     *
     * Twig's line is 1-based and counts from the template's own first line, so
     * the location is directly openable. When there is no source context — a
     * runtime error raised from a function rather than from template text —
     * the fallback is the template's own first line, which is the closest
     * honest answer available.
     *
     * The fallback is `$template` as given, NOT `$template . '.twig'`: callers
     * pass the name already normalized (see {@see \Lava\View\ViewRenderer}),
     * and appending the extension here would put a second copy of the
     * renderer's own naming rule in a problem class — two places to change, and
     * a `broken.twig.twig` in the message the day they disagree.
     */
    private static function source(string $template, Error $error): SourceLocation
    {
        $source = $error->getSourceContext();
        $line = $error->getTemplateLine();

        return SourceLocation::of(
            $source?->getPath() ?? $template,
            $line > 0 ? $line : 1,
        );
    }

    private static function where(string $template, Error $error): string
    {
        return (string) self::source($template, $error);
    }

    /**
     * The variable name out of a strict_variables message, for the fix hint.
     *
     * Matched rather than parsed: Twig's message is
     * `Variable "title" does not exist.` and it is a human sentence, not a
     * contract. If the shape ever changes the fix degrades to a generic
     * `|default('')`, which is still correct advice — so this is a hint that
     * gets better when it can, never a parser that breaks the problem.
     */
    private static function guessVariable(RuntimeError $error): string
    {
        if (preg_match('/Variable "([^"]+)"/', $error->getRawMessage(), $matches) === 1) {
            return $matches[1];
        }

        return 'the variable';
    }

    public function code(): string
    {
        return 'template_failed';
    }
}
