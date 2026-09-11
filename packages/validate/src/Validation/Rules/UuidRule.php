<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must be a canonical UUID.
 *
 * Accepts the 8-4-4-4-12 hyphenated form, case-insensitively, for any version
 * — v4 is what `uuid()` usually means, but a field that holds an id from
 * another system will see v1, v5 and v7, and refusing those would make the
 * rule about the generator rather than about the format. The RFC 9562 layout
 * is what is checked: version and variant nibbles are NOT, because a UUID with
 * a hand-edited version is still a UUID, and the database is going to store it
 * as text either way.
 *
 * The brace-and-URN forms (`{…}`, `urn:uuid:…`) are refused. They are legal
 * spellings of the same value, and accepting both would mean a column holding
 * two different strings for one id — the fix text names the form to send.
 */
final class UuidRule extends Rule
{
    public function name(): string
    {
        return 'uuid';
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return null;
        }

        return new RuleViolation(
            "Field '{$field}' must be a UUID.",
            "Send '{$field}' in the hyphenated form, e.g. 3f2504e0-4f89-41d3-9a0c-0305e82c3301 — no braces, no urn: prefix.",
        );
    }
}
