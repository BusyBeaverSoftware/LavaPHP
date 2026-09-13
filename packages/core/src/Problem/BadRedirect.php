<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A `$r->redirect()` route that could not answer: a status that is not a
 * redirect, or a target that is unknown, does not answer GET, is a redirect
 * itself, or has a param the redirect's own path does not capture.
 *
 * Each would otherwise surface on the first visit to the old address, as a 500
 * from URL generation or a redirect into a 404 or a loop, which is the one
 * request nobody tests: the address that was supposed to keep working.
 */
final class BadRedirect extends LavaProblem
{
    public function code(): string
    {
        return 'bad_redirect';
    }

    public static function status(string $name, int $status, ?SourceLocation $source = null): self
    {
        return new self(
            "Redirect route '{$name}' uses status {$status}, which is not a redirect.",
            'Use 301 or 308 for a permanent move, or 302, 303 or 307 for a temporary one.',
            ['route' => $name, 'status' => $status],
            $source,
        );
    }

    public static function unknownTarget(string $name, string $to, ?string $nearest, ?SourceLocation $source = null): self
    {
        return new self(
            "Redirect route '{$name}' points at '{$to}', and no route has that name.",
            $nearest === null
                ? 'Name a route registered in app/Routes.php or by an enabled pack; `lava routes` lists them.'
                : "Did you mean '{$nearest}'? `lava routes` lists every route name.",
            ['route' => $name, 'to' => $to],
            $source,
        );
    }

    /** @param list<string> $methods */
    public static function targetNotGet(string $name, string $to, array $methods, ?SourceLocation $source = null): self
    {
        return new self(
            "Redirect route '{$name}' points at '{$to}', which answers " . implode(', ', $methods) . ' but not GET.',
            'A browser follows a redirect with GET. Point the redirect at a route that answers GET.',
            ['route' => $name, 'to' => $to, 'methods' => $methods],
            $source,
        );
    }

    public static function targetIsRedirect(string $name, string $to, ?string $final, ?SourceLocation $source = null): self
    {
        return new self(
            "Redirect route '{$name}' points at '{$to}', which is itself a redirect.",
            $final === null
                ? "Point it at the route '{$to}' leads to, so a visitor makes one hop rather than a chain that can loop."
                : "Point it at '{$final}', where '{$to}' leads, so a visitor makes one hop rather than a chain that can loop.",
            ['route' => $name, 'to' => $to],
            $source,
        );
    }

    /** @param array<string, string> $captured the redirect's own params, name => type */
    public static function param(string $name, string $to, string $param, string $type, array $captured, ?SourceLocation $source = null): self
    {
        $own = $captured === []
            ? 'its path captures nothing'
            : 'its path captures ' . implode(', ', array_map(static fn (string $p, string $t): string => "{{$p}:{$t}}", array_keys($captured), $captured));

        return new self(
            isset($captured[$param])
                ? "Redirect route '{$name}' captures '{$param}' as {$captured[$param]}, and '{$to}' needs it as {$type}."
                : "Redirect route '{$name}' cannot fill the param '{$param}' of '{$to}': {$own}.",
            "Capture it in the redirect's path as {{$param}:{$type}}, so every value it matches is one '{$to}' accepts.",
            ['route' => $name, 'to' => $to, 'param' => $param, 'type' => $type, 'captured' => $captured],
            $source,
        );
    }
}
