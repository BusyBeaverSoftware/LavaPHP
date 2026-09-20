<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Map\ApiIndex;
use Lava\Core\Tests\Support\PhpSnippet;
use PHPUnit\Framework\TestCase;

/**
 * Every PHP snippet in `docs/` is checked against the code it teaches.
 *
 * Documentation was the largest single category of bug three rounds of outside
 * builds found — twelve of about forty-four — and two of the four recipe pages
 * written to fix earlier rounds shipped with their own drift. Prose rots faster
 * than anything else in this repository because nothing reads it: a method can
 * be renamed and the page goes on calling the old name, in the one place a
 * reader has been told to trust.
 *
 * So the pages are read here, the way {@see ApiExampleTest} reads `lava api`'s
 * examples and {@see FrameworkReferenceTest} reads AGENTS.md's. What is proved:
 * every fence parses, every `Lava\` name it writes exists, every static call it
 * makes exists on the class it names, and every instance call on a variable
 * whose type the snippet itself states exists on that type. That last one is the
 * shape that actually rotted — a doc calling `$config->integer(…)`, a method
 * `Config` never had.
 *
 * What it cannot prove: that a fragment would run (they are fragments on
 * purpose), or that a call on a variable the snippet never types lands
 * anywhere — the receiver is unknowable, and guessing would fail snippets that
 * are right.
 *
 * **A fence is checked or it says why not.** A snippet that shows a mistake on
 * purpose opts out with `<!-- lava-docs: skip — reason -->` on the line before
 * it, and the count of fences found must equal checked plus skipped, so a new
 * page cannot arrive unchecked and unnoticed.
 */
final class DocumentationSnippetTest extends TestCase
{
    /** The marker that opts one fence out, with the reason it states. */
    private const SKIP = '/^<!--\s*lava-docs:\s*skip\s*(?:—|-|–)\s*(.+?)\s*-->$/u';

    public function testEverySnippetIsValidPhp(): void
    {
        foreach (self::snippets() as $where => $code) {
            $error = PhpSnippet::parseError($code);
            self::assertNull($error, "the snippet at {$where} is not valid PHP: {$error}");
        }
    }

    public function testEveryFrameworkNameASnippetWritesExists(): void
    {
        $prefixes = array_map(
            static fn (\Lava\Core\Map\ApiSurface $surface): string => $surface->namespacePrefix(),
            ApiIndex::surfaces(),
        );

        foreach (self::snippets() as $where => $code) {
            foreach (PhpSnippet::lavaNames($code) as $name) {
                // A pack that is not installed cannot be spoken for here; core's
                // suite has to pass on a checkout of core alone.
                $installed = array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($name, $prefix));
                if ($installed === []) {
                    continue;
                }
                self::assertTrue(
                    class_exists($name) || interface_exists($name) || enum_exists($name),
                    "the snippet at {$where} names {$name}, which does not exist",
                );
            }
        }
    }

    public function testEveryStaticCallASnippetMakesExists(): void
    {
        foreach (self::snippets() as $where => $code) {
            $imports = PhpSnippet::imports($code);

            foreach (PhpSnippet::staticCalls($code) as [$written, $method]) {
                $target = PhpSnippet::resolve($written, $imports);
                if ($target === null) {
                    continue;
                }
                self::assertTrue(
                    method_exists($target, $method),
                    "the snippet at {$where} calls {$target}::{$method}(), which does not exist",
                );
            }
        }
    }

    /**
     * The check that would have caught the drift the reviews found: a call on a
     * variable the snippet itself declares the type of.
     */
    public function testEveryCallOnATypedVariableExistsOnThatType(): void
    {
        $checked = 0;

        foreach (self::snippets() as $where => $code) {
            $imports = PhpSnippet::imports($code);

            foreach (PhpSnippet::typedInstanceCalls($code, $imports) as [$class, $method, $variable]) {
                $checked++;
                self::assertTrue(
                    method_exists($class, $method),
                    "the snippet at {$where} calls \${$variable}->{$method}() on {$class}, which has no such method",
                );
            }
        }

        // Asserted rather than assumed: a run where the pattern matched nothing
        // would pass while checking none of the calls this exists to check.
        self::assertGreaterThan(10, $checked, 'almost no typed calls were found in docs/ — is the pattern still right?');
    }

    /**
     * No fence is silently unchecked: found equals checked plus skipped, and a
     * skip states its reason.
     */
    public function testEveryFenceIsCheckedOrSaysWhyNot(): void
    {
        $root = self::skipUnlessTheDocsAreHere();

        $found = 0;
        $skipped = [];
        foreach (self::pages($root) as $page) {
            foreach (self::fences($page) as $fence) {
                $found++;
                if ($fence['skip'] !== null) {
                    $skipped[] = $fence['where'] . ': ' . $fence['skip'];
                }
            }
        }

        self::assertSame(
            $found,
            count(self::snippets()) + count($skipped),
            'a PHP fence in docs/ is neither checked nor marked — add `<!-- lava-docs: skip — reason -->` above it, or fix it',
        );
        self::assertGreaterThan(40, $found, 'the fences in docs/ have almost disappeared — is the reader still right?');

        foreach ($skipped as $reason) {
            self::assertMatchesRegularExpression('/: .{10,}$/', $reason, "a skipped fence must say why: {$reason}");
        }
    }

    /**
     * Every checked snippet, keyed by `path:line` so a failure names the fence.
     *
     * @return array<string, string>
     */
    private static function snippets(): array
    {
        $root = self::skipUnlessTheDocsAreHere();

        $snippets = [];
        foreach (self::pages($root) as $page) {
            foreach (self::fences($page) as $fence) {
                if ($fence['skip'] !== null) {
                    continue;
                }
                $snippets[$fence['where']] = $fence['code'];
            }
        }

        return $snippets;
    }

    /**
     * Every ```php fence in one page, with the line it starts on and the skip
     * marker directly above it, when there is one.
     *
     * @return list<array{where: string, code: string, skip: string|null}>
     */
    private static function fences(string $page): array
    {
        $lines = explode("\n", (string) file_get_contents($page));
        $relative = self::relative($page);

        $fences = [];
        $open = null;
        $body = [];

        foreach ($lines as $number => $line) {
            $trimmed = rtrim($line, "\r");

            if ($open !== null) {
                if (trim($trimmed) === '```') {
                    $fences[] = [
                        'where' => $relative . ':' . ($open + 1),
                        'code' => implode("\n", $body),
                        'skip' => self::skipMarker($lines, $open),
                    ];
                    $open = null;
                    $body = [];
                    continue;
                }
                $body[] = $trimmed;
                continue;
            }

            if (preg_match('/^```php\b/', trim($trimmed)) === 1) {
                $open = $number;
            }
        }

        return $fences;
    }

    /**
     * The reason the fence opening at `$at` is not checked, or null.
     *
     * Read from the line above, skipping a blank one, so the marker can sit
     * against the fence or against the paragraph that introduces it.
     *
     * @param list<string> $lines
     */
    private static function skipMarker(array $lines, int $at): ?string
    {
        for ($line = $at - 1; $line >= 0 && $line >= $at - 2; $line--) {
            $text = trim($lines[$line]);
            if ($text === '') {
                continue;
            }

            return preg_match(self::SKIP, $text, $marker) === 1 ? $marker[1] : null;
        }

        return null;
    }

    /** @return list<string> */
    private static function pages(string $root): array
    {
        $pages = array_merge(glob($root . '/docs/*.md') ?: [], glob($root . '/docs/packs/*.md') ?: []);
        sort($pages);

        return $pages;
    }

    private static function relative(string $page): string
    {
        $root = self::root();

        return $root === null ? $page : ltrim(substr($page, strlen($root)), '/');
    }

    private static function skipUnlessTheDocsAreHere(): string
    {
        $root = self::root();

        if ($root === null || glob($root . '/packages/*/src') === []) {
            self::markTestSkipped('no docs/ and no packages above ' . __DIR__);
        }

        return $root;
    }

    private static function root(): ?string
    {
        $directory = __DIR__;

        while ($directory !== dirname($directory)) {
            if (is_file($directory . '/docs/conventions.md')) {
                return $directory;
            }
            $directory = dirname($directory);
        }

        return null;
    }
}
