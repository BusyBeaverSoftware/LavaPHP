<?php

declare(strict_types=1);

namespace Lava\Validate\Validation;

use Lava\Validate\Validation\Rules\RequiredRule;

/**
 * One field's ordered rules, and the decision of whether a value passes.
 *
 * **The first failure wins; later rules do not run.** A rule set is a
 * pipeline, not a set: every rule after a type rule assumes the type held, so
 * reporting `min(18)` on a value of `'abc'` would be a second, meaningless
 * complaint about a comparison that never happened. One problem per field is
 * also what an agent can act on — "send a number" is a fix; "send a number,
 * and also it is below 18" is noise about a value that does not exist yet.
 *
 * **Presence is decided once, by {@see RequiredRule::isPresent()}.** A field
 * that is not required and whose value is absent skips every rule, which is
 * what makes `->email()` on its own mean "if it is there, it must be an
 * email". A field that IS required and absent reports that and nothing else —
 * the other rules have no value to inspect, and "required" is the only true
 * thing left to say about it.
 *
 * The set does not know its field's name; the name arrives with the value,
 * exactly as it does for a single {@see Rule}. A rule set is therefore a
 * reusable value — the same `int()->min(18)` can serve two fields — and there
 * is one place that decides what a field is called.
 */
final readonly class RuleSet
{
    /** @param list<Rule> $rules */
    public function __construct(private array $rules)
    {
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function isRequired(): bool
    {
        return $this->requiredRule() !== null;
    }

    /**
     * The one failure this value produced, or null when it passed.
     *
     * Returns a {@see RuleFailure} rather than a bare `RuleViolation` because
     * this is the last point that knows WHICH rule stopped the pipeline. The
     * rule travels with its own text so a `validation_failed` problem can
     * report the failing rule's name and what it expected, without any rule
     * having to name itself.
     *
     * `$present` is passed in rather than derived from the value, because
     * whether the key was in the input at all is a fact the validator already
     * knows — and a rule set that guessed from the value could not tell an
     * absent field from one sent as `null`. The two need different fixes
     * ("include the key" vs "send a value"), so the distinction has to survive
     * this far.
     */
    public function inspect(string $field, mixed $value, bool $present): ?RuleFailure
    {
        if (!RequiredRule::isPresent($value)) {
            $required = $this->requiredRule();
            if ($required === null) {
                return null;
            }
            return new RuleFailure($required, $present
                ? new RuleViolation(
                    "Field '{$field}' is required and was sent empty.",
                    "Send a value for '{$field}', or omit the key entirely if it is optional.",
                )
                : new RuleViolation(
                    "Field '{$field}' is required and was not sent.",
                    "Include '{$field}' in the request body.",
                ));
        }

        foreach ($this->rules as $rule) {
            // Required already passed — the value is present. Skipping it here
            // rather than inside the rule keeps "required" out of every other
            // rule's business.
            if ($rule instanceof RequiredRule) {
                continue;
            }
            $violation = $rule->inspect($field, $value);
            if ($violation !== null) {
                return new RuleFailure($rule, $violation);
            }
        }

        return null;
    }

    private function requiredRule(): ?RequiredRule
    {
        foreach ($this->rules as $rule) {
            if ($rule instanceof RequiredRule) {
                return $rule;
            }
        }
        return null;
    }
}
