<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * A lower bound — on a number's value, or on a string's length.
 *
 * **Which one is decided when the rule is built, not when a value arrives.**
 * This is the pack's one genuinely ambiguous rule: `min(2)` against the string
 * `'5'` is either "at least two characters" (false) or "at least the number
 * two" (true), and no amount of inspecting the value can tell which the author
 * meant. Guessing would make the rule's answer depend on the data, which is
 * the worst property a validation rule can have.
 *
 * So {@see \Lava\Validate\Validation\Field} decides, because it is the only
 * thing that knows: `Field::int()->min(18)` builds a numeric bound and
 * `Field::str()->min(2)` builds a length bound. A field with no type rule has
 * nothing to decide from, and `min()` there is refused with
 * {@see InvalidRule} rather than guessed — the fix is to add a type, which is
 * the missing information.
 *
 * Length is counted in characters, not bytes, so `min(3)` accepts "José".
 * Counting bytes would make the rule's answer depend on the alphabet.
 */
final class MinRule extends Rule
{
    private function __construct(
        private readonly int|float $bound,
        private readonly bool $byLength,
    ) {
    }

    /** At least `$value` — for a field that has already been established as numeric. */
    public static function numeric(int|float $value): self
    {
        return new self($value, byLength: false);
    }

    /** At least `$characters` characters — for a field that has already been established as a string. */
    public static function length(int $characters): self
    {
        if ($characters < 0) {
            throw InvalidRule::negativeLength('min', $characters);
        }
        return new self($characters, byLength: true);
    }

    public function name(): string
    {
        return 'min';
    }

    /** @return array{bound: int|float, of: string} */
    public function expects(): mixed
    {
        return ['bound' => $this->bound, 'of' => $this->byLength ? 'characters' : 'value'];
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if ($this->byLength) {
            $length = is_string($value) ? mb_strlen($value) : null;
            if ($length !== null && $length >= $this->bound) {
                return null;
            }

            return new RuleViolation(
                "Field '{$field}' must be at least {$this->bound} characters, but {$length} were sent.",
                "Send '{$field}' with at least {$this->bound} characters.",
            );
        }

        if ((is_int($value) || is_float($value)) && $value >= $this->bound) {
            return null;
        }
        if (is_string($value) && is_numeric($value) && (float) $value >= $this->bound) {
            return null;
        }

        return new RuleViolation(
            "Field '{$field}' must be at least {$this->bound}.",
            "Send '{$field}' as a number no lower than {$this->bound}.",
        );
    }
}
