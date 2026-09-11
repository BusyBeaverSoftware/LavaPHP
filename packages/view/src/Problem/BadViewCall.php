<?php

declare(strict_types=1);

namespace Lava\View\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * A template called `url()` or `feature()` with an argument those functions
 * cannot use.
 *
 * This exists because the alternative is a PHP `TypeError` or an "Object of
 * class App\User could not be converted to string" raised from inside Twig's
 * compiled template — a message that names a class the template author never
 * typed and a line in a file under `var/views/` that no longer resembles what
 * they wrote. The template is the artifact at fault, and the template is what
 * the fix has to name.
 *
 * All three factories share one code because a reader acts on them identically:
 * read the fix, edit the template. Splitting them would give three codes whose
 * only difference is which sentence of the fix applies.
 */
final class BadViewCall extends LavaProblem
{
    /** `url()` was given something that is not a route name. */
    public static function routeName(mixed $value): self
    {
        return new self(
            'url() was given ' . self::describe($value) . ' instead of a route name.',
            "Pass the route's name as a string literal: {{ url('users.show', {id: item.id}) }}. "
            . 'Names are what app/Routes.php passes to ->get()/->post() — `lava routes` lists them all.',
            ['given' => get_debug_type($value)],
        );
    }

    /**
     * `url()`'s second argument was not a map of param name => scalar.
     *
     * The classic version of this is passing the model instead of the id —
     * `{{ url('users.show', {id: user}) }}` — which reads as correct until you
     * know that a route param is always a string in a URL.
     */
    public static function urlParams(string $route, mixed $value): self
    {
        return new self(
            "url('{$route}', …) was given " . self::describe($value) . ' as its params, which is not a map of param names to values.',
            "Pass an object literal: {{ url('{$route}', {id: item.id}) }}. A route param is a string or a number — "
            . 'pass the field, not the object that holds it.',
            ['route' => $route, 'given' => get_debug_type($value)],
        );
    }

    /**
     * A param value that cannot become part of a URL.
     *
     * `null` lands here as often as objects do, and it is worth its own mention
     * in the fix: `{{ url('users.show', {id: user.id}) }}` on a user that was
     * not loaded passes `null`, and the honest fix is to guard the link in the
     * template rather than to render a URL with a hole in it.
     */
    public static function urlParam(string $route, string $param, mixed $value): self
    {
        return new self(
            "url('{$route}', …) was given " . self::describe($value) . " for param '{$param}', which cannot be part of a URL.",
            "Pass a string or an integer — {{ url('{$route}', {{$param}: item.{$param}}) }}. "
            . 'If the value can be null, guard the link: {% if item.' . $param . ' is not null %}…{% endif %}.',
            ['route' => $route, 'param' => $param, 'given' => get_debug_type($value)],
        );
    }

    /** `feature()` was given something that is not a flag name. */
    public static function featureName(mixed $value): self
    {
        return new self(
            'feature() was given ' . self::describe($value) . ' instead of a flag name.',
            "Pass the flag's name as a string literal: {% if feature('beta_dashboard') %}. "
            . 'The name is the one in config/features.php — `lava features` lists them all.',
            ['given' => get_debug_type($value)],
        );
    }

    /**
     * What was given, for a reader who is looking at a template.
     *
     * A type, not a value: a dumped object would put a user's data in an error
     * page and a log line, and the type is what the fix is about anyway.
     */
    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        return 'a ' . get_debug_type($value);
    }

    public function code(): string
    {
        return 'bad_view_call';
    }
}
