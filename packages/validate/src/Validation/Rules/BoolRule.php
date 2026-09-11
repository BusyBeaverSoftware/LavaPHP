<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must be a boolean.
 *
 * The accepted spellings are exactly the ones the wire produces, and no more:
 *
 *   JSON   true, false, 1, 0
 *   form   1, 0, "1", "0", "true", "false", "on", "off"
 *
 * `on`/`off` are here because an HTML checkbox sends `on`, and a form route is
 * the most common place a boolean arrives — refusing it would mean every form
 * handler doing its own `isset()`. `yes`/`no`/`y`/`n` are NOT here: they are
 * natural language, and the set of natural-language spellings has no end. A
 * caller who wants them writes `->custom()` and says so.
 *
 * The fix text lists every accepted spelling, so the set is discoverable from
 * the failure itself rather than from documentation.
 */
final class BoolRule extends Rule
{
    /** @var list<string> the string spellings that mean true, lowercase */
    private const TRUE_SPELLINGS = ['1', 'true', 'on'];

    /** @var list<string> the string spellings that mean false, lowercase */
    private const FALSE_SPELLINGS = ['0', 'false', 'off'];

    public function name(): string
    {
        return 'boolean';
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (self::accepts($value)) {
            return null;
        }

        return new RuleViolation(
            "Field '{$field}' must be a boolean, but " . get_debug_type($value) . ' was sent.',
            "Send '{$field}' as true or false — 1, 0, \"1\", \"0\", \"true\", \"false\", \"on\" and \"off\" are accepted too.",
        );
    }

    /**
     * Whether the value is one of the accepted spellings.
     *
     * The single definition of what this rule accepts: {@see inspect()} and
     * {@see \Lava\Validate\Validation\Validated::bool()} both call it, so a
     * field that validated can always be read back as a bool. Two independent
     * spellings lists would eventually disagree, and the failure would be a
     * field that validates as `"on"` and then reads as `false`.
     */
    public static function accepts(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if (is_int($value)) {
            return $value === 0 || $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), [...self::TRUE_SPELLINGS, ...self::FALSE_SPELLINGS], true);
        }
        return false;
    }

    /**
     * The value as a PHP bool, for {@see \Lava\Validate\Validation\Validated::bool()}.
     *
     * Lives here so the accepted spellings are defined once: a rule that
     * accepted `"on"` and an accessor that did not would validate the field and
     * then read it as `false`.
     *
     * Anything {@see accepts()} refused reads as `false` — but the accessor
     * checks acceptance first, so that is not a silent default, it is an
     * unreachable branch.
     */
    public static function coerce(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), self::TRUE_SPELLINGS, true);
        }
        return false;
    }
}
