<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must be one of a fixed set of values.
 *
 * **Compared by string form, not by identity.** A form sends `'18'` where JSON
 * sends `18`, and an allowed set of `[18, 21]` has to accept both or the same
 * request succeeds or fails depending on its content type. Comparing
 * `(string) $value` against `(string) $allowed` makes the wire irrelevant,
 * which is the only behaviour a caller can predict.
 *
 * The cost is that `true`, `1` and `'1'` are one value here. That is stated
 * rather than hidden, and it is the right trade for a rule whose entire job is
 * matching text a client typed.
 *
 * An empty allowed set is refused at construction: it is a rule that nothing
 * can satisfy, and the caller almost certainly meant to build the list first.
 */
final class InRule extends Rule
{
    /** @var list<string> the allowed values, as strings, in the order given */
    private readonly array $allowed;

    /** @param list<string|int|float> $allowed */
    public function __construct(array $allowed)
    {
        if ($allowed === []) {
            throw InvalidRule::emptyAllowedSet();
        }

        $this->allowed = array_map(
            static fn (string|int|float $value): string => (string) $value,
            $allowed,
        );
    }

    public function name(): string
    {
        return 'in';
    }

    /** @return list<string> */
    public function expects(): mixed
    {
        return $this->allowed;
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (is_scalar($value) && in_array((string) $value, $this->allowed, true)) {
            return null;
        }

        $list = implode(', ', $this->allowed);

        return new RuleViolation(
            "Field '{$field}' must be one of: {$list}.",
            "Send '{$field}' as exactly one of: {$list}.",
        );
    }
}
