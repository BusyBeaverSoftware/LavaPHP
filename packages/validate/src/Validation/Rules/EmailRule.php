<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must look like an email address.
 *
 * `filter_var(FILTER_VALIDATE_EMAIL)` does the deciding. It is PHP's own
 * implementation of the address grammar, it is maintained with the language,
 * and it is strict about the things that matter for storage — no spaces, one
 * `@`, a dotted domain. A hand-written regex here would be a second, worse
 * implementation of a rule nobody can state precisely anyway.
 *
 * What this rule does NOT claim: that the address exists, that anyone reads it,
 * or that it is spelled the way the owner spells it. It is a shape check, and
 * the only honest way to verify an address is to send mail to it. The fix text
 * says "an address", not "your address", because a caller who mistyped a real
 * mailbox gets the same answer as one who invented a fake.
 */
final class EmailRule extends Rule
{
    public function name(): string
    {
        return 'email';
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return null;
        }

        return new RuleViolation(
            "Field '{$field}' must be an email address.",
            "Send '{$field}' as an address with one @ and a domain, e.g. ada@example.com.",
        );
    }
}
