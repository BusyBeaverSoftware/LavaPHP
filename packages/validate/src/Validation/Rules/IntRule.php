<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must be a whole number.
 *
 * Accepts an int, and a string of digits — because that is what the wire
 * carries. A form-encoded body delivers every value as text, and JSON delivers
 * whatever the client's serializer chose, so `{"age": "42"}` and `{"age": 42}`
 * are the same intent expressed two ways, and only one of them is an int.
 * Refusing the string form would make the same request succeed or fail
 * depending on the content type, which is a difference no caller can see.
 *
 * Rejects a float even when it is whole (`42.0`): the caller asked for an
 * integer and sent a real number, and silently truncating is how `1.5` becomes
 * `1`. A leading `+`, an exponent, or surrounding whitespace are all refused
 * for the same reason — they are text that means a number, not a number.
 *
 * **Also rejects a digit string too large for PHP to hold.** `(int)` on
 * `'99999999999999999999'` does not fail — it produces a wrong number, silently
 * and immediately, and every later rule then reasons about a value the caller
 * never sent. A field that cannot hold what arrived has to say so; the range
 * check is the only place that can.
 */
final class IntRule extends Rule
{
    /** PHP_INT_MAX as text, for a range check that float rounding cannot fudge. */
    private const MAX_DIGITS = '9223372036854775807';

    /** |PHP_INT_MIN| as text. The negative limit has one more value than the positive one. */
    private const MIN_MAGNITUDE_DIGITS = '9223372036854775808';

    public function name(): string
    {
        return 'integer';
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (self::coerce($value) !== null) {
            return null;
        }

        // A digit string that coerce() refused is out of range, not malformed —
        // it matched the digit pattern and still did not fit.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return new RuleViolation(
                "Field '{$field}' is a whole number too large to hold: it must fit between -"
                . self::MIN_MAGNITUDE_DIGITS . ' and ' . self::MAX_DIGITS . '.',
                "Send '{$field}' as a number within that range, or declare the field as Field::str() if the value is an identifier rather than something to do arithmetic on.",
            );
        }

        $arrived = is_string($value)
            ? 'a string that is not a whole number'
            : get_debug_type($value);

        return new RuleViolation(
            "Field '{$field}' must be an integer, but {$arrived} was sent.",
            "Send '{$field}' as a whole number, e.g. 42. A numeric string such as \"42\" is accepted; 42.0 is not.",
        );
    }

    /**
     * The value as a PHP int, or null when it is not one this system can hold.
     *
     * The single definition of what this rule accepts: {@see inspect()} and
     * {@see \Lava\Validate\Validation\Validated::int()} both call it, so a value
     * that passes validation can always be read back.
     */
    public static function coerce(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        return self::fitsInAnInt($value) ? (int) $value : null;
    }

    /**
     * Whether a digit string is within PHP's int range, compared as TEXT.
     *
     * Casting to float and comparing would be wrong at exactly the boundary that
     * matters: `(float) '9223372036854775808'` and `(float) PHP_INT_MAX` are the
     * same double, so a float comparison accepts one past the maximum and then
     * casts it to a wrong number. Comparing digit strings by length first, and
     * lexically only when the lengths match, is exact and needs no float at all.
     */
    private static function fitsInAnInt(string $digits): bool
    {
        $negative = str_starts_with($digits, '-');
        $magnitude = ltrim($negative ? substr($digits, 1) : $digits, '0');

        if ($magnitude === '') {
            return true; // zero, however it was spelled
        }

        $limit = $negative ? self::MIN_MAGNITUDE_DIGITS : self::MAX_DIGITS;

        if (strlen($magnitude) !== strlen($limit)) {
            return strlen($magnitude) < strlen($limit);
        }

        return strcmp($magnitude, $limit) <= 0;
    }
}
