<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Core\Problem\SourceLocation;
use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Validation\DeclarationSite;
use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * An escape hatch: a predicate the app supplies, with the message and fix
 * written by the same person who wrote the predicate.
 *
 * This is the rule that keeps the pack from having to guess every constraint an
 * app will ever need. "Slug must not be a reserved word", "end date is after
 * start date", "SKU matches our supplier's format" — none of those belong in a
 * framework, and all of them are one closure away.
 *
 * **The message and fix are required, and they are not generated.** The pack
 * could say "Field 'slug' failed the 'slug' rule", and that sentence is worth
 * nothing: it names the failure without saying what was expected or what to
 * send instead. Since only the author knows what the predicate tests, only the
 * author can write the sentence that makes the failure actionable. Requiring
 * both arguments at the call site is how that stays true — there is no default
 * to fall back on.
 *
 * `{field}` in either string is replaced with the field's name, so a renamed
 * field does not leave a stale name in the message. It is the only substitution
 * this class performs.
 *
 * A predicate that THROWS is a bug in the app, not a validation failure, and it
 * is reported as one: {@see InvalidRule} carries the original exception as its
 * `previous`, so the stack trace survives. Quietly treating "my predicate blew
 * up" as "your input was bad" would be the worst available answer — it blames
 * the caller for the app's defect and looks actionable while doing it.
 *
 * The declaration site is captured HERE, in the constructor, because the throw
 * happens later. By the time `inspect()` runs, the app's `->custom(…)` call is
 * no longer on the stack and a backtrace would report the framework's handler
 * invoker as the culprit. Capturing it at construction is the only moment the
 * line is still visible — see {@see DeclarationSite}.
 */
final class CustomRule extends Rule
{
    /** Where the app wrote `->custom(…)`. Carried so a thrown predicate can be located. */
    private readonly SourceLocation $declaredAt;

    /**
     * @param string $name The rule's name, used as the `rule` in a failure's context.
     * @param \Closure(mixed): bool $check The predicate. True means the value is acceptable.
     * @param string $message WHAT failed. May contain `{field}`.
     * @param string $fix IMPERATIVE fix. May contain `{field}`.
     * @param mixed $expects Optional machine-readable statement of the constraint, for the problem's context.
     */
    public function __construct(
        private readonly string $name,
        private readonly \Closure $check,
        private readonly string $message,
        private readonly string $fix,
        private readonly mixed $expects = null,
    ) {
        $this->declaredAt = DeclarationSite::ofCaller();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function expects(): mixed
    {
        return $this->expects;
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        try {
            $passed = ($this->check)($value);
        } catch (\Throwable $thrown) {
            throw InvalidRule::predicateFailed($this->name, $field, $thrown, $this->declaredAt);
        }

        if ($passed) {
            return null;
        }

        return new RuleViolation(
            str_replace('{field}', $field, $this->message),
            str_replace('{field}', $field, $this->fix),
        );
    }
}
