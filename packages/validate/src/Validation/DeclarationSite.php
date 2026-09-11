<?php

declare(strict_types=1);

namespace Lava\Validate\Validation;

use Lava\Core\Problem\SourceLocation;

/**
 * Where in the app a rule or field was declared.
 *
 * A `LavaProblem`'s `source` is the user-authored artifact at fault, and for
 * this pack that is the line of the fluent chain — `Field::str()->regex(…)` in
 * a controller or a validation service. Finding it means walking the stack past
 * this package's own frames, and the reason it lives in its own class is that
 * there are two moments the walk can happen and only one of them is right:
 *
 *  - **at declaration**, from a rule's constructor or a builder method. The
 *    stack still contains the app's call site, so the walk finds it. Every
 *    factory on {@see \Lava\Validate\Problem\InvalidRule} that throws while a
 *    chain is being built works this way.
 *  - **at inspection**, from `Rule::inspect()`. By then the declaration is over
 *    and its frames are gone; the first frame outside this package is the
 *    handler INVOCATION site, so the walk would report
 *    `HandlerInvoker.php` — framework code, blamed as though it were the app's
 *    mistake. A rule that can only fail at inspection time therefore has to
 *    capture its site when it is built and carry it, which is what
 *    {@see Rules\CustomRule} does.
 *
 * Skipping by directory rather than by a fixed depth is what survives
 * refactoring: `Field::regex()` reaches a factory through
 * `RegexRule::__construct()`, while `Field::custom()` reaches one through a
 * closure frame, and any fixed index would be wrong for one of them.
 */
final class DeclarationSite
{
    /**
     * This package's own source directory — the only thing skipped.
     *
     * `src`, not the package root, and the difference is not cosmetic: a pack's
     * tests and fixtures live under its root, so skipping the whole root would
     * also skip `packages/validate/tests/fixtures/apps/<app>/app/Http/…` — a
     * fixture's controller IS the app's call site, and the walk would sail past
     * it to the framework's handler invoker. Only the pack's shipped code is
     * "not the app".
     */
    private const SOURCE_ROOT = __DIR__ . '/..';

    public static function ofCaller(): SourceLocation
    {
        $root = realpath(self::SOURCE_ROOT) ?: self::SOURCE_ROOT;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null) {
                continue;
            }
            $real = realpath($file) ?: $file;
            if (str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            return SourceLocation::of($file, $frame['line'] ?? 0);
        }

        // Nothing outside the package: a rule built in a REPL, a test helper, or
        // an eval'd string. Reporting the unknown is honest; inventing a
        // location would send the reader to a file that has nothing to do with it.
        return SourceLocation::of('unknown', 0);
    }
}
