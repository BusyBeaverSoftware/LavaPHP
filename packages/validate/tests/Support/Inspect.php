<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Support;

use Lava\Validate\Validation\Rule;
use PHPUnit\Framework\Assert;

/**
 * Rule-level assertions, shared because every rule test makes the same three
 * claims about every value.
 *
 * The third one is the interesting one. `assertDoesNotQuote()` is how the
 * pack's promise that a failure message never contains the value that was sent
 * is enforced rather than merely documented — and it is a promise worth
 * testing, because the easiest way to write a helpful message ("expected
 * 'editor' or 'admin', got 'root'") is exactly the way to leak a password into
 * a log.
 */
final class Inspect
{
    /** Asserts the rule accepts the value, and returns nothing so the call reads as a statement. */
    public static function accepts(Rule $rule, mixed $value, string $field = 'field'): void
    {
        Assert::assertNull(
            $rule->inspect($field, $value),
            "{$rule->name()}() should have accepted " . self::describe($value),
        );
    }

    /**
     * Asserts the rule refuses the value, and returns the text the author wrote for that refusal.
     *
     * `$mayQuote` exists for the one legitimate exception to the non-disclosure
     * rule: a message that states a BOUND can print the same digits the caller
     * sent, because the caller sent exactly the bound. `IntRule` says "must fit
     * between … and 9223372036854775807", and a test feeding it
     * `'9223372036854775808'` would see a containment that is a coincidence of
     * the boundary rather than a leak. The exemption is opt-in per call so that
     * it has to be justified where it is used.
     */
    public static function refuses(Rule $rule, mixed $value, string $field = 'field', bool $mayQuote = false): Violation
    {
        $violation = $rule->inspect($field, $value);

        Assert::assertNotNull(
            $violation,
            "{$rule->name()}() should have refused " . self::describe($value),
        );

        $refusal = new Violation($violation->message, $violation->fix);
        if (!$mayQuote) {
            self::assertDoesNotQuote($refusal, $value);
        }

        return $refusal;
    }

    /**
     * The message must not contain the value that was sent.
     *
     * Only checked for strings longer than three characters: a one- or
     * two-character value (`'5'`, `'on'`) turns up inside ordinary words and
     * inside other numbers in the message ("at least 12 characters"), so a
     * containment check would report leaks that are not there. The check is
     * therefore sound but not complete, which is the right trade for a test.
     */
    private static function assertDoesNotQuote(Violation $refusal, mixed $value): void
    {
        if (!is_string($value) || mb_strlen($value) <= 3) {
            return;
        }

        Assert::assertStringNotContainsString(
            $value,
            $refusal->message,
            'a failure message quoted the value that was sent; the value belongs in the problem context, where it can be redacted',
        );
    }

    public static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . (mb_strlen($value) > 40 ? mb_substr($value, 0, 40) . '…' : $value) . "'";
        }

        return var_export($value, true);
    }
}
