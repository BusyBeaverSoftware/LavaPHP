<?php

declare(strict_types=1);

namespace Lava\Validate\Validation;

use Lava\Validate\Problem\ValidationFailed;
use Lava\Validate\Validation\Rules\RequiredRule;

/**
 * A declared set of fields, and the one method that runs them against a payload.
 *
 * ```php
 * $validator = Validator::of([
 *     'email' => Field::str()->required()->email()->max(254),
 *     'age'   => Field::int()->required()->min(18),
 * ]);
 *
 * $input = $validator->validate($request->getParsedBody() ?? []);
 * if ($input->failed()) {
 *     return HttpErrors::forReport($input->report(), $request, $this->env);
 * }
 * ```
 *
 * **Every field is checked, not just the first that fails.** A caller fixing a
 * form should learn about all four bad fields in one round trip; failing fast
 * would turn one request into four. The cost is that rules cannot depend on
 * each other's success across fields, which is a real limit and an honest one:
 * "end date is after start date" is a `->custom()` on the end field, reading
 * the start date from the raw payload, because cross-field logic is not
 * something a per-field pipeline can express.
 *
 * **The validator is a value, built once and reusable.** It holds no request
 * and no state, so the same `Validator::of([...])` can live in a service and
 * run for every request — or be built inline in a handler, which is what the
 * example above does. The field map is also the allow-list: keys in the payload
 * that no field declares are dropped by {@see Validated}, so a form handler
 * cannot accidentally pass through an extra key.
 *
 * **`array_key_exists`, not `isset`.** The difference is exactly the case the
 * fixes distinguish: a key sent as `null` IS sent, and the caller who sent it
 * needs "send a value", not "include the key". Presence is a fact about the
 * payload's keys, so it is read from the payload's keys.
 */
final class Validator
{
    /** @var array<string, Field> */
    private readonly array $fields;

    /** @param array<string, Field> $fields */
    private function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * @param array<string, Field> $fields keyed by field name — the key IS the name
     */
    public static function of(array $fields): self
    {
        return new self($fields);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function validate(array $input): Validated
    {
        $values = [];
        $failures = [];

        foreach ($this->fields as $name => $field) {
            $present = array_key_exists($name, $input);
            $value = $present ? $input[$name] : null;

            $failure = $field->rules()->inspect($name, $value, $present);

            if ($failure !== null) {
                $failures[] = ValidationFailed::of($name, $failure, $value, $present);
                continue;
            }

            // A field that is not required and was not sent is simply not in
            // the result — the same definition of "present" the rules used, so
            // a field can never validate as present and read as absent.
            if (RequiredRule::isPresent($value)) {
                $values[$name] = $value;
            }
        }

        return new Validated($values, $failures);
    }
}
