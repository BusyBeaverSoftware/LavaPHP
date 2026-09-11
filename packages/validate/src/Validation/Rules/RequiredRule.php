<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must be there, and must not be empty.
 *
 * This rule owns the framework's single definition of "present", and every
 * other rule defers to it. That is deliberate: if each rule decided for
 * itself, `->str()->min(2)` and `->int()->min(2)` could disagree about whether
 * `''` counts as a value, and the disagreement would show up as a field that
 * passes one rule set and fails another for the same input.
 *
 * Present means: not null, not an empty array, and not a string that is empty
 * or only whitespace. `0`, `false` and `'0'` are all PRESENT — they are
 * values a caller meant to send, and treating a legitimate zero as "missing"
 * is the classic validation bug. A string of spaces is not present, because a
 * form field with spaces in it is an empty form field, and the fix for it
 * ("send a value") is the same fix.
 */
final class RequiredRule extends Rule
{
    public function name(): string
    {
        return 'required';
    }

    /**
     * The one rule whose expectation is not a comparison, so it states the
     * requirement in words: a value has to be present. It travels into a
     * `validation_failed` problem's context, where an agent reading JSON should
     * not have to infer "must be present" from a null `expects`.
     *
     * @return array{present: true}
     */
    public function expects(): mixed
    {
        return ['present' => true];
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (self::isPresent($value)) {
            return null;
        }

        return new RuleViolation(
            "Field '{$field}' is required and was sent empty.",
            "Send a value for '{$field}', or omit the key entirely if it is optional.",
        );
    }

    /**
     * The framework's definition of a value that is there.
     *
     * Public and static because it is a fact about a value, not about this
     * rule: {@see \Lava\Validate\Validation\RuleSet} uses it to decide whether
     * to run anything at all, and {@see \Lava\Validate\Validation\Validated}
     * uses it to decide whether a field is present in the result.
     */
    public static function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }
}
