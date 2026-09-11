<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Problem\LavaProblem;
use PHPUnit\Framework\TestCase;

/**
 * `docs/problem-codes.md` is a contract, not prose.
 *
 * The catalog is the document an agent is told to trust, and it makes a
 * specific claim in its first paragraph: every code maps 1:1 to one Problem
 * class. Nothing used to read the file, so a code could be renamed in the class
 * and the table would go on describing the old name — a document lying in
 * exactly the place a reader has been told not to doubt. This reads both sides
 * and compares them.
 *
 * **The consequence is the point.** A new problem class now means a new row in
 * the same commit, or the suite goes red. That is what makes the table a
 * registry rather than a description of what the registry happened to look like
 * the last time someone remembered to update it.
 *
 * It lives in core's suite and reaches across every pack. That is not core
 * depending on a pack: the packs are installed in the monorepo's own dev
 * requirements, and this is a test of the *repository's* document, which no
 * single package can own — a pack cannot check a table it does not have, and
 * core cannot enumerate packs it must not depend on. Which is also why it skips
 * rather than fails when the catalog is not above it: a checkout of core alone
 * has neither the document nor the packages, and a test that cannot mean
 * anything should say so instead of inventing an answer.
 */
final class ProblemCodeRegistryTest extends TestCase
{
    public function testTheTableAndTheClassesListTheSameCodes(): void
    {
        $root = $this->skipUnlessTheCatalogIsHere();

        $declared = array_keys(self::declared($root));
        $registered = array_keys(self::registered($root));
        sort($declared);
        sort($registered);

        // Both sides sorted, so a failure prints the one code that differs
        // rather than the whole table. The message matters more than usual
        // here: the two lists look alike, and which one is missing an entry is
        // the only question the reader has.
        self::assertSame($registered, $declared, implode("\n", [
            'the codes in docs/problem-codes.md and the codes the Problem classes declare',
            'are no longer the same set. A code only in the table is a row describing a',
            'class that does not exist (or no longer declares it); a code only in a class',
            'is one an agent cannot look up. Both are fixed by editing the table — and a',
            'new problem class means a new row in the SAME commit.',
        ]));
    }

    /**
     * The table's class column is checked separately from the code set, because
     * the two can disagree one at a time: moving a code from one class to
     * another keeps both sets identical while leaving the table pointing at the
     * class that used to raise it.
     */
    public function testEachCodeIsMappedToOneClassAndTheTableNamesIt(): void
    {
        $root = $this->skipUnlessTheCatalogIsHere();

        $declared = self::declared($root);
        $registered = self::registered($root);

        foreach ($declared as $code => $declaration) {
            self::assertSame(
                self::shortName($declaration['class']),
                $registered[$code]['class'] ?? '(no row)',
                "the row for `{$code}` names a class that does not declare it",
            );
        }
    }

    /**
     * core's rows carry no suffix; a pack's row names the pack. That suffix is
     * the reader's answer to "which `composer require` fixes this", so it is
     * part of the contract rather than decoration.
     */
    public function testThePackSuffixNamesThePackageThatDeclaresTheCode(): void
    {
        $root = $this->skipUnlessTheCatalogIsHere();

        foreach (self::declared($root) as $code => $declaration) {
            $owner = $declaration['package'] === 'core' ? null : $declaration['package'];

            self::assertSame(
                $owner,
                self::registered($root)[$code]['package'] ?? null,
                "the row for `{$code}` names the wrong pack (or core's rows must carry no suffix)",
            );
        }
    }

    /**
     * Every `LavaProblem` code the packages declare, keyed by code.
     *
     * The classes are reflected on rather than read out of a class map: a map
     * built from the same source would be free to agree with the table about
     * nothing. `src/Problem/` is where the document says a problem class lives,
     * so a problem declared anywhere else is caught by the other half of this
     * file — its row would be in the table and nothing here would declare it.
     *
     * @return array<string, array{class: class-string<LavaProblem>, package: string}>
     */
    private static function declared(string $root): array
    {
        $declared = [];

        foreach (glob($root . '/packages/*/src/Problem/*.php') ?: [] as $file) {
            $class = self::classOf($file);

            // Null is the enum and any file with no class declaration at all;
            // these are the directory's support classes — the report, the two
            // renderers, Severity, SourceLocation — none of which is a problem.
            if ($class === null || !is_subclass_of($class, LavaProblem::class)) {
                continue;
            }

            $code = self::codeOf($class, $file);
            self::assertArrayNotHasKey(
                $code,
                $declared,
                "two classes declare the code `{$code}`; a code is one thing an agent matches on",
            );

            $declared[$code] = [
                'class' => $class,
                'package' => basename(dirname($file, 3)),
            ];
        }

        return $declared;
    }

    /**
     * The fully-qualified name of the class a file declares, or null when it
     * declares none.
     *
     * Read out of the file's own `namespace` and `class` lines. `class` is
     * matched literally, so `enum Severity` is not a match and needs no special
     * case; the modifiers are allowed in any order, so `final readonly class`
     * is.
     */
    private static function classOf(string $file): ?string
    {
        $source = (string) file_get_contents($file);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }
        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $name) !== 1) {
            return null;
        }

        $class = trim($namespace[1]) . '\\' . $name[1];

        // A file in an installed pack must autoload. If it does not, the pack
        // is not installed or its psr-4 root has moved, and saying so beats
        // reporting a code set that is missing one member for no visible reason.
        self::assertTrue(
            class_exists($class),
            "{$file} declares {$class}, which does not autoload — run composer install",
        );

        return $class;
    }

    /**
     * The code a problem class declares.
     *
     * Instantiated WITHOUT its constructor, because the constructor takes the
     * failing input and the fix text: arguments of the throw site, which have
     * nothing to do with which code the class is. `code()` is a declaration —
     * a literal return in all 53 classes — so an instance with uninitialised
     * properties can answer. If that ever stops being true this fails loudly
     * instead of quietly reporting a wrong code, which is the only reason the
     * catch is worth writing.
     */
    private static function codeOf(string $class, string $file): string
    {
        try {
            $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            if (!$instance instanceof LavaProblem) {
                self::fail("{$class} is not a LavaProblem");
            }

            return $instance->code();
        } catch (\Throwable $failure) {
            self::fail("could not read the code {$class} declares ({$file}): {$failure->getMessage()}");
        }
    }

    /**
     * The registry table, keyed by code.
     *
     * A row is `| `code` | `Class` (pack) | thrown when | fix |`, and the
     * pattern above is deliberately anchored to both pipes: the header, the
     * separator, and any prose that happens to contain a backticked word are
     * all skipped without being named here.
     *
     * @return array<string, array{class: string, package: string|null}>
     */
    private static function registered(string $root): array
    {
        $rows = [];

        foreach (explode("\n", (string) file_get_contents($root . '/docs/problem-codes.md')) as $line) {
            if (preg_match(
                '/^\| `([a-z_]+)` \| `([A-Za-z][A-Za-z0-9]*)`(?: \(([a-z-]+)\))? \|/',
                $line,
                $row,
            ) !== 1) {
                continue;
            }

            $rows[$row[1]] = [
                'class' => $row[2],
                'package' => ($row[3] ?? '') === '' ? null : $row[3],
            ];
        }

        return $rows;
    }

    /** The repository root, or a skipped test when these tests cannot mean anything. */
    private function skipUnlessTheCatalogIsHere(): string
    {
        $root = self::root();

        if ($root === null || glob($root . '/packages/*/src/Problem/*.php') === []) {
            self::markTestSkipped('no docs/problem-codes.md and no packages above ' . __DIR__);
        }

        return $root;
    }

    /**
     * The repository root, found by walking up for the catalog.
     *
     * Found rather than derived from `__DIR__` by counting `..`, because the
     * count changes the day this file moves and the failure would be a skip
     * that looks like a pass.
     */
    private static function root(): ?string
    {
        $directory = __DIR__;

        while ($directory !== dirname($directory)) {
            if (is_file($directory . '/docs/problem-codes.md')) {
                return $directory;
            }
            $directory = dirname($directory);
        }

        return null;
    }

    private static function shortName(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
