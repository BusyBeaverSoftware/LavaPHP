<?php

declare(strict_types=1);

namespace Lava\Validate\Validation;

use Lava\Core\Problem\ProblemReport;
use Lava\Validate\Problem\UnreadableField;
use Lava\Validate\Problem\ValidationFailed;
use Lava\Validate\Validation\Rules\BoolRule;
use Lava\Validate\Validation\Rules\FloatRule;
use Lava\Validate\Validation\Rules\IntRule;
use Lava\Validate\Validation\Rules\RequiredRule;
use Lava\Validate\Validation\Rules\StringRule;

/**
 * The result of validating a payload: the values that were there, and the
 * problems that stopped the rest.
 *
 * **Both halves are available at once.** A 422 response should list every bad
 * field so the caller can fix them in one round trip, which means the
 * validator cannot stop at the first failure — but a handler also must not
 * read values from a payload that failed. Keeping both on one object makes the
 * two-step shape explicit and hard to skip: `failed()` first, then the values.
 * There is no mode in which a `Validated` holds half-valid data.
 *
 * **Only declared fields are kept.** `all()` and `value()` see the fields the
 * Validator declared and nothing else, so an extra key in the request body —
 * `"is_admin": true` on a profile form — never reaches the handler. That is a
 * property of the shape rather than a filter someone has to remember to call:
 * the Validator's field map is the allow-list.
 *
 * **Absent means absent, by the same definition the rules use.** A field sent
 * as `null`, `''`, `[]` or not sent at all is not present, so `has()` is false
 * and `value()` is null. That definition lives in
 * {@see RequiredRule::isPresent()} and is not restated here; two definitions of
 * "present" would eventually disagree, and the disagreement would look like a
 * field that validates and then cannot be read.
 *
 * **The typed accessors are strict.** `int('age')` returns an int or throws
 * {@see UnreadableField} — it never returns 0 as a fallback, because 0 is a
 * value a handler will happily store. Each accessor delegates to the very rule
 * that validated the field (`IntRule::coerce()`, `BoolRule::coerce()`, …), so
 * "accepted by validation" and "readable by the handler" are one decision made
 * in one place.
 */
final class Validated
{
    /**
     * @param array<string, mixed> $values The declared fields that are present.
     * @param list<ValidationFailed> $failures One per field that failed.
     */
    public function __construct(
        private readonly array $values,
        private readonly array $failures,
    ) {
    }

    public function valid(): bool
    {
        return $this->failures === [];
    }

    public function failed(): bool
    {
        return $this->failures !== [];
    }

    /** @return list<ValidationFailed> one per failed field, in declaration order */
    public function problems(): array
    {
        return $this->failures;
    }

    /** The failures as a boot-style report, ready for `HttpErrors::forReport()`. */
    public function report(): ProblemReport
    {
        $report = new ProblemReport();
        foreach ($this->failures as $failure) {
            $report->add($failure);
        }

        return $report;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    /** The value as it arrived, or null when the field is absent. */
    public function value(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    /**
     * Every present, declared field, keyed by name.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function string(string $field): string
    {
        return $this->readString($field) ?? $this->unreadable($field, 'string');
    }

    public function int(string $field): int
    {
        return $this->readInt($field) ?? $this->unreadable($field, 'int');
    }

    public function float(string $field): float
    {
        return $this->readFloat($field) ?? $this->unreadable($field, 'float');
    }

    public function bool(string $field): bool
    {
        return $this->readBool($field) ?? $this->unreadable($field, 'bool');
    }

    /**
     * The four readers, each dispatching to the rule that owns that type's
     * acceptance. The rules are the definition of what a type accepts; these
     * are only the lookup, and they exist one per type so each accessor's
     * return type is the reader's return type minus null.
     */
    private function readString(string $field): ?string
    {
        return $this->has($field) ? StringRule::coerce($this->values[$field]) : null;
    }

    private function readInt(string $field): ?int
    {
        return $this->has($field) ? IntRule::coerce($this->values[$field]) : null;
    }

    private function readFloat(string $field): ?float
    {
        return $this->has($field) ? FloatRule::coerce($this->values[$field]) : null;
    }

    private function readBool(string $field): ?bool
    {
        if (!$this->has($field)) {
            return null;
        }
        $value = $this->values[$field];

        // Two steps because a boolean has a truthy spelling and a false one,
        // and `false` is a readable value — not the same thing as unreadable.
        return BoolRule::accepts($value) ? BoolRule::coerce($value) : null;
    }

    /**
     * Never returns — the accessor's declared return type is the caller's
     * promise that the field is readable, and this is how that promise is
     * enforced rather than assumed.
     *
     * @return never
     */
    private function unreadable(string $field, string $type): never
    {
        if (!$this->has($field)) {
            throw UnreadableField::absent($field, $type);
        }

        throw UnreadableField::notCoercible($field, $type, $this->values[$field]);
    }
}
