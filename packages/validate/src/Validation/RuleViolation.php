<?php

declare(strict_types=1);

namespace Lava\Validate\Validation;

/**
 * One field's rule failed: what is wrong, and what to do about it.
 *
 * Message and fix are authored TOGETHER, in the rule, because that is where
 * the knowledge lives. A rule that returned only a message would leave the
 * fix to be assembled by whoever catches it — and an assembled fix is generic
 * ("check the field"), while the useful fix names the bound, the allowed set,
 * or the shape that would have passed.
 *
 * **A message never quotes the value that was sent.** The value goes in the
 * problem's `context`, where it can be redacted by name; a message is prose
 * that gets logged, pasted into issues, and rendered to a browser, and a
 * password that appears in a sentence is a password in a log. Where the value
 * itself is safe and useful — a length, a count — the rule says that instead.
 */
final readonly class RuleViolation
{
    public function __construct(
        /** What is wrong, one sentence, naming the field. */
        public string $message,
        /** The imperative fix: the shape of a value that would pass. */
        public string $fix,
    ) {
    }
}
