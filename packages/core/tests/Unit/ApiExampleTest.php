<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Map\ApiIndex;
use PHPUnit\Framework\TestCase;

/**
 * An example cannot teach an API the framework does not have.
 *
 * `lava api` is read exactly when the reader does not know the framework, so an
 * example naming a renamed method is worse than no example: it is the framework
 * telling someone to write code that cannot run. {@see FrameworkReferenceTest}
 * makes the same guarantee for the app-file snippets in AGENTS.md; this one
 * generalises it — the methods are parsed out of each snippet rather than kept
 * in a list beside it, so there is no second list to fall behind.
 *
 * What it proves: every snippet parses, every `Lava\` name it writes exists,
 * every static call it makes on a named framework class exists on that class,
 * every instance-method name it calls exists somewhere in the index, and every
 * example mentions the class it is an example of.
 *
 * What it cannot prove: that a snippet is a complete runnable file (they are
 * fragments on purpose), and that a name from a pack exists when that pack is
 * not installed — core's suite must pass with core alone, so those names are
 * checked only when their package is present, exactly as FrameworkReferenceTest
 * documents for its own `Lava\Db\DbModule` mention.
 */
final class ApiExampleTest extends TestCase
{
    public function testEverySnippetIsValidPhp(): void
    {
        foreach (self::examples() as $class => $code) {
            try {
                token_get_all("<?php\n" . $code, TOKEN_PARSE);
            } catch (\ParseError $error) {
                self::fail("the example for {$class} is not valid PHP: {$error->getMessage()}");
            }
        }

        self::assertNotSame([], self::examples());
    }

    public function testEveryFrameworkNameAnExampleWritesExists(): void
    {
        $prefixes = array_map(
            static fn (\Lava\Core\Map\ApiSurface $surface): string => $surface->namespacePrefix(),
            ApiIndex::surfaces(),
        );

        foreach (self::examples() as $class => $code) {
            foreach (self::lavaNames($code) as $name) {
                // A name from a package that is not installed cannot be checked
                // here; the package that owns it checks its own examples.
                $installed = array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($name, $prefix));
                if ($installed === []) {
                    continue;
                }
                self::assertTrue(
                    class_exists($name) || interface_exists($name) || enum_exists($name),
                    "the example for {$class} names {$name}, which does not exist",
                );
            }
        }
    }

    public function testEveryStaticCallOnAFrameworkClassExists(): void
    {
        foreach (self::examples() as $class => $code) {
            $imports = self::imports($code);

            preg_match_all('/([A-Za-z_][A-Za-z0-9_\\\\]*)::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $written, $method]) {
                $target = $imports[$written] ?? $written;
                if (!str_starts_with($target, 'Lava\\') || !class_exists($target) && !interface_exists($target) && !enum_exists($target)) {
                    continue;
                }
                self::assertTrue(
                    method_exists($target, $method),
                    "the example for {$class} calls {$target}::{$method}(), which does not exist",
                );
            }
        }
    }

    /**
     * An instance call's receiver is a variable, so the class it lands on cannot
     * be read from the snippet. The method NAME still can, and a renamed method
     * is exactly what rots an example — so the name must exist on something the
     * framework indexes.
     */
    public function testEveryInstanceMethodAnExampleCallsExistsSomewhere(): void
    {
        $known = [];
        foreach (self::index()->symbols as $symbol) {
            foreach ($symbol['methods'] as $method) {
                $known[strtolower($method['name'])] = true;
            }
        }

        $checked = 0;
        foreach (self::examples() as $class => $code) {
            // Only calls on a variable: `$this->events->dispatch(…)` and
            // `$db->fetch(…)`. A call chained onto another call is the same shape.
            preg_match_all('/(?:\$[A-Za-z_][A-Za-z0-9_]*|\))\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $matches);
            foreach ($matches[1] as $method) {
                $checked++;
                self::assertArrayHasKey(
                    strtolower($method),
                    $known,
                    "the example for {$class} calls ->{$method}(), which no indexed class has",
                );
            }
        }

        // Asserted, not assumed: a run where the regex matched nothing would
        // otherwise pass while checking none of the calls it exists to check.
        self::assertGreaterThan(20, $checked, 'the examples make almost no instance calls — is the pattern still right?');
    }

    public function testEveryExampleMentionsTheClassItIsFor(): void
    {
        foreach (self::examples() as $class => $code) {
            $short = strrpos($class, '\\') === false ? $class : substr($class, strrpos($class, '\\') + 1);
            self::assertTrue(
                str_contains($code, $class) || str_contains($code, $short),
                "the example for {$class} never mentions it",
            );
        }
    }

    /** @return array<class-string, string> */
    private static function examples(): array
    {
        $examples = [];
        foreach (ApiIndex::surfaces() as $surface) {
            foreach ($surface->examples() as $class => $code) {
                $examples[$class] = $code;
            }
        }

        return $examples;
    }

    private static function index(): ApiIndex
    {
        return ApiIndex::of(ApiIndex::surfaces(), dirname(__DIR__, 4));
    }

    /**
     * The `use Lava\…;` lines a snippet declares, short name => fully qualified.
     *
     * @return array<string, string>
     */
    private static function imports(string $code): array
    {
        preg_match_all('/^\s*use\s+(Lava\\\\[A-Za-z0-9_\\\\]+);/m', $code, $matches);

        $imports = [];
        foreach ($matches[1] as $name) {
            $at = strrpos($name, '\\');
            $imports[$at === false ? $name : substr($name, $at + 1)] = $name;
        }

        return $imports;
    }

    /** @return list<string> */
    private static function lavaNames(string $code): array
    {
        preg_match_all('/Lava\\\\[A-Za-z0-9_\\\\]+/', $code, $matches);

        return array_values(array_unique($matches[0]));
    }
}
