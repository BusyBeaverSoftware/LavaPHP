<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must be a string.
 *
 * No coercion, and that is not an oversight. Every other scalar rule has a
 * wire format to decode — a form sends numbers as text, so accepting `"42"`
 * for an int is reading the wire correctly. A string has no such problem:
 * JSON and forms both deliver text as text, so a number arriving where a
 * string was declared means the caller meant a number. Coercing it would
 * quietly accept `{"name": 42}` and hand the handler `"42"`, which is the
 * kind of help that hides a client bug.
 */
final class StringRule extends Rule
{
    public function name(): string
    {
        return 'string';
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (self::coerce($value) !== null) {
            return null;
        }

        return new RuleViolation(
            "Field '{$field}' must be a string, but " . get_debug_type($value) . ' was sent.',
            "Send '{$field}' as text, e.g. \"ada\". Numbers, booleans and objects are not strings.",
        );
    }

    /**
     * The value as a PHP string, or null when it is not one.
     *
     * The identity function, written out so that reading a field and validating
     * it are the same decision. {@see \Lava\Validate\Validation\Validated::string()}
     * calls this, and because it is the rule's own acceptance check, a field
     * that passed validation can always be read back — the two can never drift
     * into "accepted `42` as a string, then refused to read it".
     */
    public static function coerce(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
