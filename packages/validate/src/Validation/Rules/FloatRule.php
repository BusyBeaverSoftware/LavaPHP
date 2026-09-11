<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must be a real number.
 *
 * Accepts an int, a float, or a numeric string — the same wire reasoning as
 * {@see IntRule}, plus the int case, because every integer is a real number.
 * `is_numeric` decides the string form, so `"1e3"` and `" 42 "` are accepted
 * where the integer rule refuses them: a float field is the one place a caller
 * is already thinking in decimal notation, and `is_numeric` is PHP's own answer
 * to "does this text mean a number".
 *
 * `INF` and `NAN` are refused even though they are floats. They survive
 * `is_numeric` only as floats, and a validated value that is not comparable —
 * `NAN` is not less than, greater than, or equal to anything, including
 * itself — would make every later rule's answer meaningless.
 */
final class FloatRule extends Rule
{
    public function name(): string
    {
        return 'float';
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (self::coerce($value) !== null) {
            return null;
        }

        // Both routes to a non-finite value: a float that IS one, and a numeric
        // string so large it overflows to one. They are the same defect.
        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return new RuleViolation(
                "Field '{$field}' must be a finite number, but a value outside the range of a float was sent.",
                "Send '{$field}' as an ordinary number, e.g. 12.5.",
            );
        }

        $arrived = is_string($value)
            ? 'a string that is not numeric'
            : get_debug_type($value);

        return new RuleViolation(
            "Field '{$field}' must be a number, but {$arrived} was sent.",
            "Send '{$field}' as a number, e.g. 12.5. A numeric string such as \"12.5\" is accepted.",
        );
    }

    /**
     * The value as a PHP float, or null when it is not a finite number.
     *
     * The single definition of what this rule accepts: {@see inspect()} and
     * {@see \Lava\Validate\Validation\Validated::float()} both call it. The
     * finiteness check applies to the numeric-string route as well, because
     * `is_numeric('1e400')` is true and `(float) '1e400'` is `INF` — a value
     * that is not comparable to anything, including itself.
     */
    public static function coerce(mixed $value): ?float
    {
        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;
            return is_finite($float) ? $float : null;
        }

        return null;
    }
}
