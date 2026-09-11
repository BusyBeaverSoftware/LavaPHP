<?php

declare(strict_types=1);

namespace Lava\Validate\Validation;

/**
 * One check a field's value must pass.
 *
 * A rule inspects a value and either passes or explains itself. It never
 * throws for a value it dislikes — a value that fails is the expected,
 * everyday outcome of validation, and the framework's rule is that expected
 * outcomes are returned while mistakes are thrown. The mistakes a rule CAN
 * throw are about the rule's own definition ({@see InvalidRule}): a negative
 * bound, an empty allowed set, an unparsable pattern. Those are the
 * developer's error, they are found at the call site, and they are a
 * different thing from a caller sending the wrong string.
 */
abstract class Rule
{
    /**
     * Stable snake_case id for this rule, in the problem's `context` and in
     * the fix text an agent reads. Never renamed once shipped.
     */
    abstract public function name(): string;

    /**
     * @return RuleViolation|null null when the value satisfies this rule
     */
    abstract public function inspect(string $field, mixed $value): ?RuleViolation;

    /**
     * The parameter this rule was built with — `18` for `min(18)`, the allowed
     * list for `in([...])` — or null for a rule with no parameter.
     *
     * It exists so the problem's `context` can say what was EXPECTED, not just
     * what failed. An agent that reads `{"field":"age","rule":"min","expects":18}`
     * can fix the request without reading any documentation; one that reads
     * only `{"field":"age","rule":"min"}` has to guess the bound.
     */
    public function expects(): mixed
    {
        return null;
    }
}
