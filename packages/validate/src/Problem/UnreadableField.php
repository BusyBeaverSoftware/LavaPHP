<?php

declare(strict_types=1);

namespace Lava\Validate\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * The app asked to read a field that has no readable value.
 *
 * This is the app's fault, not the caller's, and it is a 500 for that reason.
 * It exists because the alternative is worse than an exception: without it,
 * `Validated::int()` on an absent field would have to return `0` or `null`, and
 * a handler would go on to write a zero into a column that should have held a
 * value. A silently wrong row is the most expensive failure this framework can
 * produce, so the accessor refuses instead.
 *
 * There are exactly two ways to reach it, and both are fixed in the field's
 * declaration rather than in the request:
 *
 *  - **absent** — the field was optional, so validation let it through missing,
 *    and the handler read it anyway. The fix is `->required()` on the field, or
 *    a `->has()` check before the read.
 *  - **notCoercible** — the value passed validation but cannot be read as the
 *    requested type. That happens when the field has no type rule
 *    (`Field::any()`) or when the accessor asks for a type the field never
 *    promised. The fix is to declare the type the handler actually wants.
 *
 * Neither is reachable from a request that a correctly-declared validator
 * accepted — which is the point. A bug in the declaration is reported at the
 * declaration, once, with a fix, instead of corrupting a value.
 */
final class UnreadableField extends LavaProblem
{
    /**
     * The builder method that declares each readable type.
     *
     * The accessor's word for a type is not always the builder's: a handler
     * reads `->string('name')`, and declares it `Field::str()`. A fix that says
     * `Field::string()` names a method that does not exist, which is worse than
     * saying nothing — an agent following it gets a fatal instead of a fix.
     */
    private const BUILDER = [
        'string' => 'str',
        'int' => 'int',
        'float' => 'float',
        'bool' => 'bool',
    ];

    public static function absent(string $field, string $type): self
    {
        return new self(
            "Cannot read field '{$field}' as {$type}: it is not present in the validated input.",
            "Declare the field with ->required() if it must be there, or check ->has('{$field}') before reading it. "
            . 'A field is absent when the key was not sent, or when it was sent as null, an empty string, or an empty array.',
            ['field' => $field, 'type' => $type, 'why' => 'absent'],
        );
    }

    public static function notCoercible(string $field, string $type, mixed $value): self
    {
        return new self(
            "Cannot read field '{$field}' as {$type}: the value that passed validation is " . get_debug_type($value) . '.',
            'Declare the field with a type rule that matches how it is read, e.g. '
            . 'Field::' . self::builder($type) . '(), so the shape is checked during validation instead of here.',
            [
                'field' => $field,
                'type' => $type,
                'why' => 'not_coercible',
                'value' => ValidationFailed::reportable($field, $value),
            ],
        );
    }

    /** The builder method for a readable type, or the type word if it is unknown. */
    private static function builder(string $type): string
    {
        return self::BUILDER[$type] ?? $type;
    }

    public function code(): string
    {
        return 'unreadable_field';
    }
}
