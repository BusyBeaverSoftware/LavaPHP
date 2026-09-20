<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every anchored regex in the framework ends the subject, not the line.
 *
 * PCRE's `$` also matches immediately before a trailing newline, so
 * `/^[a-z]+$/` accepts `"admin\n"`. Three separate reviews found three separate
 * instances of that one mistake — a column name, an HTTP method, and the
 * pattern `->regex()` builds for an app — which is the signature of something
 * that needs a guard rather than another fix.
 *
 * The rule: a pattern ending in `$` carries `D` (or `m`, which makes `$` mean
 * the end of a line on purpose). `\z` would say the same thing inside the
 * pattern; `D` is used here because it is one character at the end of a string
 * the pattern is often built by concatenating.
 *
 * Lives in core's suite and reaches across every pack for the same reason
 * `ProblemCodeRegistryTest` does: this is a property of the repository, which
 * no single package can check, and a checkout of core alone should say so
 * rather than invent an answer.
 */
final class AnchoredRegexTest extends TestCase
{
    /**
     * Lines where a `$` anchor without `D` is not a validator.
     *
     * A reason, not a hall pass: each entry says why the pattern is text rather
     * than something the framework runs against input.
     *
     * @var array<string, string> `<file>:<line>` => why
     */
    private const NOT_A_VALIDATOR = [
        'packages/validate/src/ValidateApiSurface.php:82' =>
            'example code shown to an app author; `->regex()` anchors it with D when it runs',
    ];

    public function testEveryAnchoredPatternEndsTheSubject(): void
    {
        $root = $this->skipUnlessThePacksAreHere();
        $offenders = [];

        foreach (self::sources($root) as $relative => $contents) {
            foreach (explode("\n", $contents) as $number => $line) {
                $trimmed = ltrim($line);
                // A docblock or comment is prose about a pattern, not one.
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }
                // The tail of a pattern literal: `$`, the delimiter, then the
                // modifiers, then the quote that closes the PHP string.
                if (preg_match_all('/\$([\/#~])([a-zA-Z]*)\'/D', $line, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($matches as $match) {
                    $modifiers = $match[2];
                    if (str_contains($modifiers, 'D') || str_contains($modifiers, 'm')) {
                        continue;
                    }
                    $at = $relative . ':' . ($number + 1);
                    if (isset(self::NOT_A_VALIDATOR[$at])) {
                        continue;
                    }
                    $offenders[] = $at . '  ' . trim($line);
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", [
            'these patterns end in `$` without the `D` modifier, so each one also',
            'matches a subject with a trailing newline — `"admin\n"` passes a check',
            'for `/^[a-z]+$/`. Add `D` (or `\z` inside the pattern), or name the line',
            'in AnchoredRegexTest::NOT_A_VALIDATOR with the reason it is not one:',
        ]));
    }

    public function testTheGuardWouldCatchTheMistakeItExistsFor(): void
    {
        // The guard is a string scan, so it is worth proving the scan sees what
        // it claims to: this is the shape all three reported instances had.
        self::assertSame(1, preg_match('/\$([\/#~])([a-zA-Z]*)\'/D', "preg_match('/^[a-z]+\$/', \$name)"));
        self::assertSame(1, preg_match('/^[a-z]+$/', "admin\n"), 'PCRE still matches before a trailing newline.');
        self::assertSame(0, preg_match('/^[a-z]+$/D', "admin\n"), 'D is what stops it.');
    }

    /** @return array<string, string> repo-relative path => contents */
    private static function sources(string $root): array
    {
        $sources = [];
        foreach (glob($root . '/packages/*/src') ?: [] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = (string) $file->getRealPath();
                $sources[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
            }
        }
        ksort($sources);

        return $sources;
    }

    private function skipUnlessThePacksAreHere(): string
    {
        $root = dirname(__DIR__, 4);
        if (!is_dir($root . '/packages/core/src')) {
            self::markTestSkipped('the packages are not above this checkout, so there is nothing to scan.');
        }

        return $root;
    }
}
