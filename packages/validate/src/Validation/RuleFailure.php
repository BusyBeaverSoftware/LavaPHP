<?php

declare(strict_types=1);

namespace Lava\Validate\Validation;

/**
 * A failed rule, paired with the text its author wrote for it.
 *
 * {@see RuleViolation} is what a rule SAYS; this is WHICH rule said it, and
 * what that rule wanted. Keeping them apart is what lets a rule stay a small
 * class that returns two sentences and nothing else: the rule never has to
 * name itself or restate its own bound, because the caller still holds the
 * rule object and can ask it.
 *
 * The pairing is what makes a `validation_failed` problem machine-actionable.
 * `context.field` says where, `context.value` says what arrived, and
 * `context.rule` plus `context.expects` say what the field wanted — so an agent
 * can fix the input without parsing the prose. The prose is still there for the
 * human reading the response.
 *
 * This is also the reason {@see RuleSet::inspect()} cannot simply return a
 * `RuleViolation`: the rule set is the last place that knows which rule in the
 * pipeline stopped, and once the violation has been returned upward that
 * information is gone.
 */
final readonly class RuleFailure
{
    public function __construct(
        public Rule $rule,
        public RuleViolation $violation,
    ) {
    }
}
