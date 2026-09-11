<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * An upper bound — on a number's value, or on a string's length.
 *
 * The mirror of {@see MinRule}, and it inherits the same reasoning: the bound's
 * *meaning* is fixed when the rule is built, by {@see \Lava\Validate\Validation\Field},
 * because `max(8)` against `'12345678'` is either "at most eight characters"
 * (true) or "at most the number eight" (true, but for a different reason) and
 * the value cannot tell you which was meant.
 *
 * Length is counted in characters, not bytes, which matters more here than it
 * does for a minimum: a byte bound would reject "José" at `max(4)` while
 * accepting "Jose", so the rule would silently be a rule about the alphabet.
 */
final class MaxRule extends Rule
{
    private function __construct(
        private readonly int|float $bound,
        private readonly bool $byLength,
    ) {
    }

    /** At most `$value` — for a field that has already been established as numeric. */
    public static function numeric(int|float $value): self
    {
        return new self($value, byLength: false);
    }

    /** At most `$characters` characters — for a field that has already been established as a string. */
    public static function length(int $characters): self
    {
        if ($characters < 0) {
            throw InvalidRule::negativeLength('max', $characters);
        }
        return new self($characters, byLength: true);
    }

    public function name(): string
    {
        return 'max';
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
            if ($length !== null && $length <= $this->bound) {
                return null;
            }

            return new RuleViolation(
                "Field '{$field}' must be at most {$this->bound} characters, but {$length} were sent.",
                "Send '{$field}' with at most {$this->bound} characters.",
            );
        }

        if ((is_int($value) || is_float($value)) && $value <= $this->bound) {
            return null;
        }
        if (is_string($value) && is_numeric($value) && (float) $value <= $this->bound) {
            return null;
        }

        return new RuleViolation(
            "Field '{$field}' must be at most {$this->bound}.",
            "Send '{$field}' as a number no higher than {$this->bound}.",
        );
    }
}
