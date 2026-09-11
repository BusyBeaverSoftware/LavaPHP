<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Rules;

use Lava\Validate\Tests\Support\Inspect;
use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\Rules\BoolRule;
use Lava\Validate\Validation\Rules\FloatRule;
use Lava\Validate\Validation\Rules\IntRule;
use Lava\Validate\Validation\Rules\StringRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The four scalar type rules, and the invariant that makes them safe to read.
 *
 * **`inspect()` and `coerce()` must agree.** That is the property this class
 * tests hardest, and it is not a stylistic preference: if `IntRule` accepts
 * `"42"` and `IntRule::coerce()` refuses it, the field passes validation and
 * then throws when the handler reads it — a 500 for a request the framework
 * itself declared good. Since both now call the same static, the agreement is
 * structural, and the test is what keeps a future edit from breaking it.
 */
final class TypeRulesTest extends TestCase
{
    /**
     * Every value either rule family has an opinion about, with what each
     * should do. One table for all four types, because the interesting cases
     * are the ones that differ BETWEEN them — a numeric string, a whole float,
     * `on`, an integer overflow.
     *
     * @return array<string, array{mixed, array<string, bool>}>
     */
    public static function values(): array
    {
        return [
            // value                          string  int    float  bool
            'a plain string' => ['ada', ['string' => true, 'int' => false, 'float' => false, 'bool' => false]],
            'a digit string' => ['42', ['string' => true, 'int' => true, 'float' => true, 'bool' => false]],
            'a negative digit string' => ['-42', ['string' => true, 'int' => true, 'float' => true, 'bool' => false]],
            'a zero-padded digit string' => ['007', ['string' => true, 'int' => true, 'float' => true, 'bool' => false]],
            'a plus-signed string' => ['+42', ['string' => true, 'int' => false, 'float' => true, 'bool' => false]],
            'a decimal string' => ['12.5', ['string' => true, 'int' => false, 'float' => true, 'bool' => false]],
            'an exponent string' => ['1e3', ['string' => true, 'int' => false, 'float' => true, 'bool' => false]],
            'a whitespace-padded number' => [' 42 ', ['string' => true, 'int' => false, 'float' => true, 'bool' => false]],
            'a non-numeric string' => ['forty-two', ['string' => true, 'int' => false, 'float' => false, 'bool' => false]],
            'an overflowing digit string' => ['99999999999999999999', ['string' => true, 'int' => false, 'float' => true, 'bool' => false]],
            'an overflowing exponent string' => ['1e400', ['string' => true, 'int' => false, 'float' => false, 'bool' => false]],

            'an int' => [42, ['string' => false, 'int' => true, 'float' => true, 'bool' => false]],
            'zero' => [0, ['string' => false, 'int' => true, 'float' => true, 'bool' => true]],
            'one' => [1, ['string' => false, 'int' => true, 'float' => true, 'bool' => true]],
            'two' => [2, ['string' => false, 'int' => true, 'float' => true, 'bool' => false]],
            'a whole float' => [42.0, ['string' => false, 'int' => false, 'float' => true, 'bool' => false]],
            'a fractional float' => [12.5, ['string' => false, 'int' => false, 'float' => true, 'bool' => false]],
            'a non-finite float' => [INF, ['string' => false, 'int' => false, 'float' => false, 'bool' => false]],

            'true' => [true, ['string' => false, 'int' => false, 'float' => false, 'bool' => true]],
            'false' => [false, ['string' => false, 'int' => false, 'float' => false, 'bool' => true]],
            'the string "on"' => ['on', ['string' => true, 'int' => false, 'float' => false, 'bool' => true]],
            'the string "OFF"' => ['OFF', ['string' => true, 'int' => false, 'float' => false, 'bool' => true]],
            'the string "yes"' => ['yes', ['string' => true, 'int' => false, 'float' => false, 'bool' => false]],

            'null' => [null, ['string' => false, 'int' => false, 'float' => false, 'bool' => false]],
            'an array' => [[1], ['string' => false, 'int' => false, 'float' => false, 'bool' => false]],
        ];
    }

    #[DataProvider('values')]
    public function testEachTypeAcceptsExactlyWhatItSaysItDoes(mixed $value, array $expected): void
    {
        self::assertSame($expected['string'], (new StringRule())->inspect('f', $value) === null, 'string');
        self::assertSame($expected['int'], (new IntRule())->inspect('f', $value) === null, 'int');
        self::assertSame($expected['float'], (new FloatRule())->inspect('f', $value) === null, 'float');
        self::assertSame($expected['bool'], (new BoolRule())->inspect('f', $value) === null, 'bool');
    }

    #[DataProvider('values')]
    public function testReadingAgreesWithValidating(mixed $value, array $expected): void
    {
        self::assertSame(
            $expected['string'],
            StringRule::coerce($value) !== null,
            'StringRule::coerce() disagrees with StringRule::inspect() — a field would validate and then fail to be read',
        );
        self::assertSame(
            $expected['int'],
            IntRule::coerce($value) !== null,
            'IntRule::coerce() disagrees with IntRule::inspect()',
        );
        self::assertSame(
            $expected['float'],
            FloatRule::coerce($value) !== null,
            'FloatRule::coerce() disagrees with FloatRule::inspect()',
        );
        self::assertSame(
            $expected['bool'],
            BoolRule::accepts($value),
            'BoolRule::accepts() disagrees with BoolRule::inspect()',
        );
    }

    public function testTheStringRuleDoesNotCoerceNumbers(): void
    {
        // A number arriving where a string was declared means the caller meant a
        // number. Coercing would hand the handler `"42"` and hide a client bug.
        self::assertNull(StringRule::coerce(42));
        Inspect::refuses(new StringRule(), 42);
    }

    public function testTheIntRuleNamesTheShapeItGotRatherThanTheValue(): void
    {
        $refusal = Inspect::refuses(new IntRule(), 'forty-two');

        self::assertStringContainsString('a string that is not a whole number', $refusal->message);
        self::assertStringContainsString('42.0 is not', $refusal->fix);
    }

    public function testAnIntegerTooLargeToHoldIsRefusedRatherThanTruncated(): void
    {
        // The whole point: `(int) '99999999999999999999'` does not fail, it
        // produces a wrong number, and every later rule then reasons about a
        // value the caller never sent.
        $refusal = Inspect::refuses(new IntRule(), '99999999999999999999');

        self::assertStringContainsString('too large to hold', $refusal->message);
        self::assertStringContainsString('Field::str()', $refusal->fix);
    }

    public function testTheIntegerRangeBoundaryIsExact(): void
    {
        // A float comparison would accept one past the maximum, because
        // `(float) PHP_INT_MAX` and `(float) '9223372036854775808'` are the same
        // double. The check compares digit strings instead.
        Inspect::accepts(new IntRule(), (string) PHP_INT_MAX);
        Inspect::accepts(new IntRule(), (string) PHP_INT_MIN);
        // `mayQuote` because the value IS the bound the message prints — the
        // containment is the boundary, not a leak.
        Inspect::refuses(new IntRule(), '9223372036854775808', mayQuote: true);
        Inspect::refuses(new IntRule(), '-9223372036854775809');

        self::assertSame(PHP_INT_MAX, IntRule::coerce((string) PHP_INT_MAX));
        self::assertSame(PHP_INT_MIN, IntRule::coerce((string) PHP_INT_MIN));
    }

    public function testTheFloatRuleRefusesAValueThatOverflowsToInfinity(): void
    {
        // `is_numeric('1e400')` is true and `(float) '1e400'` is INF — a value
        // that is not comparable to anything, including itself.
        self::assertTrue(is_numeric('1e400'));
        self::assertNull(FloatRule::coerce('1e400'));
        Inspect::refuses(new FloatRule(), '1e400');
    }

    public function testTheBooleanRuleListsEveryAcceptedSpellingInItsFix(): void
    {
        // The set is discoverable from the failure rather than from docs.
        $refusal = Inspect::refuses(new BoolRule(), 'maybe');

        foreach (['"1"', '"0"', '"true"', '"false"', '"on"', '"off"'] as $spelling) {
            self::assertStringContainsString($spelling, $refusal->fix);
        }
        self::assertStringNotContainsString('yes', $refusal->fix, 'natural-language spellings are not accepted, and the fix must not imply they are');
    }

    public function testCoercionProducesTheDeclaredType(): void
    {
        self::assertSame(42, IntRule::coerce('42'));
        self::assertSame(-7, IntRule::coerce('-7'));
        self::assertSame(7, IntRule::coerce('007'));
        self::assertSame(12.5, FloatRule::coerce('12.5'));
        self::assertSame(42.0, FloatRule::coerce(42));
        self::assertTrue(BoolRule::coerce('on'));
        self::assertTrue(BoolRule::coerce('1'));
        self::assertFalse(BoolRule::coerce('off'));
        self::assertFalse(BoolRule::coerce(0));
    }

    public function testEveryTypeRuleNamesItselfForTheProblemContext(): void
    {
        // The name travels into a validation_failed problem's `rule` field, so an
        // agent can act on it without parsing prose.
        $names = [
            (new StringRule())->name() => 'string',
            (new IntRule())->name() => 'integer',
            (new FloatRule())->name() => 'float',
            (new BoolRule())->name() => 'boolean',
        ];

        foreach ($names as $actual => $expected) {
            self::assertSame($expected, $actual);
        }
    }

    public function testARuleIsARule(): void
    {
        // All four are usable through the abstract type, which is what
        // RuleSet and Field depend on.
        foreach ([new StringRule(), new IntRule(), new FloatRule(), new BoolRule()] as $rule) {
            self::assertInstanceOf(Rule::class, $rule);
        }
    }
}
