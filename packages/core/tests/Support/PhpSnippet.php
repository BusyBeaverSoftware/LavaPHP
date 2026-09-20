<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Support;

/**
 * Reads a PHP fragment the way a checker needs to read it: what it parses to,
 * which framework names it writes, and which calls it makes.
 *
 * Three tests need the same answers — the examples `lava api` ships
 * ({@see \Lava\Core\Tests\Unit\ApiExampleTest}), the snippets in `docs/`
 * ({@see \Lava\Core\Tests\Unit\DocumentationSnippetTest}), and the prose in
 * every problem's `fix` ({@see \Lava\Core\Tests\Unit\FixTextTest}) — and three
 * copies of a regex is how two of them quietly stop agreeing. The parsing is
 * deliberately shallow: these are fragments, not files, so this reads what is
 * written rather than what it would mean if it ran.
 */
final class PhpSnippet
{
    /**
     * Why the snippet does not parse, or null when it does.
     *
     * A fragment is tried in the three contexts documentation writes one in: a
     * file, a class body (`public function complete(…) { … }` shown on its own),
     * and a function body (a few statements). Only a fragment that parses in
     * none of them is wrong — requiring the whole file around a method would
     * make the docs worse to read in exchange for making them checkable, which
     * is the wrong trade.
     */
    public static function parseError(string $code): ?string
    {
        $contexts = [
            static fn (string $php): string => $php,
            static fn (string $php): string => "class LavaSnippetContext {\n{$php}\n}",
            static fn (string $php): string => "function lava_snippet_context() {\n{$php}\n}",
            // An array body: the rules of one field, the entries of a config
            // file — shown without the `return [` that would only be noise.
            static fn (string $php): string => "\$lavaSnippetContext = [\n" . rtrim(rtrim(trim($php), ';'), ',') . "\n];",
        ];

        $first = null;
        foreach ($contexts as $wrap) {
            try {
                token_get_all("<?php\n" . $wrap(self::body($code)), \TOKEN_PARSE);

                return null;
            } catch (\ParseError $error) {
                $first ??= $error->getMessage();
            }
        }

        return $first;
    }

    /**
     * The `use Lava\…;` lines a snippet declares, short name => fully qualified.
     *
     * @return array<string, string>
     */
    public static function imports(string $code): array
    {
        preg_match_all('/^\s*use\s+(Lava\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/m', $code, $matches, PREG_SET_ORDER);

        $imports = [];
        foreach ($matches as $match) {
            $name = $match[1];
            $alias = ($match[2] ?? '') !== '' ? $match[2] : null;
            if ($alias === null) {
                $at = strrpos($name, '\\');
                $alias = $at === false ? $name : substr($name, $at + 1);
            }
            $imports[$alias] = $name;
        }

        return $imports;
    }

    /**
     * Every fully-qualified `Lava\…` name the snippet writes out.
     *
     * @return list<string>
     */
    public static function lavaNames(string $code): array
    {
        preg_match_all('/Lava\\\\[A-Za-z0-9_\\\\]+/', $code, $matches);

        return array_values(array_unique(array_map(
            static fn (string $name): string => rtrim($name, '\\'),
            $matches[0],
        )));
    }

    /**
     * Static calls, as `[class as written, method]` pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function staticCalls(string $code): array
    {
        preg_match_all('/([A-Za-z_][A-Za-z0-9_\\\\]*)::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $matches, PREG_SET_ORDER);

        $calls = [];
        foreach ($matches as [, $written, $method]) {
            $calls[] = [$written, $method];
        }

        return $calls;
    }

    /**
     * Instance-method names called on a variable or on another call's result.
     *
     * The receiver is not resolved: this is the name alone, which is still the
     * thing a rename breaks.
     *
     * @return list<string>
     */
    public static function instanceCalls(string $code): array
    {
        preg_match_all('/(?:\$[A-Za-z_][A-Za-z0-9_]*|\))\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $matches);

        return array_values($matches[1]);
    }

    /**
     * Instance calls whose receiver's class the snippet states, as
     * `[class, method, variable]` triples.
     *
     * A variable's class is knowable in exactly two shapes an example uses: a
     * type written before it (`function complete(TaskRepository $tasks)`, and
     * promoted constructor properties, which are the same text), and
     * `$x = new Type(...)`. Everything else — a call's return value, a container
     * `get()` — is left alone rather than guessed, because a wrong guess here
     * would fail a snippet that is right.
     *
     * This is the shape that rots: `$config->integer(…)` in a doc read exactly
     * like this, naming a method `Config` never had.
     *
     * @param array<string, string> $imports short name => fully qualified
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function typedInstanceCalls(string $code, array $imports): array
    {
        $types = [];

        preg_match_all('/([A-Za-z_][A-Za-z0-9_\\\\]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*->)/', $code, $declared, PREG_SET_ORDER);
        foreach ($declared as [, $type, $variable]) {
            $types[$variable] ??= $type;
        }

        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*new\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*\(/', $code, $built, PREG_SET_ORDER);
        foreach ($built as [, $variable, $type]) {
            $types[$variable] = $type;
        }

        $calls = [];
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $variable, $method]) {
            $written = $types[$variable] ?? null;
            if ($written === null) {
                continue;
            }
            $resolved = self::resolve($written, $imports);
            if ($resolved === null) {
                continue;
            }
            $calls[] = [$resolved, $method, $variable];
        }

        return $calls;
    }

    /**
     * A name as written resolved to a framework class that exists, or null when
     * it is not one this checker can speak for: an app's own class, a PSR
     * interface, a vendor type, or a pack that is not installed.
     *
     * @param array<string, string> $imports short name => fully qualified
     */
    public static function resolve(string $written, array $imports): ?string
    {
        $name = ltrim($written, '\\');
        $name = $imports[$name] ?? $name;

        if (!str_starts_with($name, 'Lava\\')) {
            return null;
        }
        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            return null;
        }

        return $name;
    }

    /** The snippet without its opening tag, so a context can be wrapped around it. */
    private static function body(string $code): string
    {
        $trimmed = ltrim($code);

        return str_starts_with($trimmed, '<?php') ? substr($trimmed, 5) : $code;
    }
}
