<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Validation;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Rules\BoolRule;
use Lava\Validate\Validation\Rules\EmailRule;
use Lava\Validate\Validation\Rules\FloatRule;
use Lava\Validate\Validation\Rules\InRule;
use Lava\Validate\Validation\Rules\IntRule;
use Lava\Validate\Validation\Rules\MaxRule;
use Lava\Validate\Validation\Rules\MinRule;
use Lava\Validate\Validation\Rules\RequiredRule;
use Lava\Validate\Validation\Rules\StringRule;
use Lava\Validate\Validation\Rules\UuidRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The builder, and the three properties that make it safe.
 *
 * A `Field` is IMMUTABLE, so a chain is a value that cannot be changed by being
 * used twice. It is OPTIONAL unless `->required()` says otherwise, so a
 * forgotten `->required()` produces an empty column rather than rejecting every
 * request that omits the field. And it starts with a TYPE, because that is what
 * makes `->min()` decidable — the pack refuses to guess a bound's meaning
 * rather than letting the answer depend on the data.
 */
final class FieldTest extends TestCase
{
    /** @return array<string, array{Field, class-string}> */
    public static function typedFields(): array
    {
        return [
            'str' => [Field::str(), StringRule::class],
            'int' => [Field::int(), IntRule::class],
            'float' => [Field::float(), FloatRule::class],
            'bool' => [Field::bool(), BoolRule::class],
            'email' => [Field::str()->email(), EmailRule::class],
            'uuid' => [Field::str()->uuid(), UuidRule::class],
        ];
    }

    /** @param class-string $expected */
    #[DataProvider('typedFields')]
    public function testATypedChainCarriesItsTypeRuleFirst(Field $field, string $expected): void
    {
        $rules = $field->rules()->rules();

        self::assertCount(1, $rules);
        self::assertInstanceOf($expected, $rules[0]);
        self::assertInstanceOf($expected, $field->typeRule());
    }

    public function testAnUntypedChainHasNoTypeRule(): void
    {
        self::assertNull(Field::any()->typeRule());
        self::assertSame([], Field::any()->rules()->rules());
    }

    public function testRequiredIsAlwaysFirstWhateverTheOrderItWasCalledIn(): void
    {
        // The rule set reports "required" before any other rule gets an opinion
        // about a value that is not there, so the order must not depend on where
        // in the chain the caller happened to write it.
        $late = Field::str()->min(2)->required()->rules()->rules();
        $early = Field::str()->required()->min(2)->rules()->rules();

        self::assertInstanceOf(RequiredRule::class, $late[0]);
        self::assertInstanceOf(RequiredRule::class, $early[0]);
        self::assertSame(
            array_map(static fn (object $r): string => $r::class, $early),
            array_map(static fn (object $r): string => $r::class, $late),
        );
    }

    public function testAChainIsAValueAndUsingItDoesNotChangeIt(): void
    {
        // The aliasing bug this prevents: building `$email` once and using it
        // under two names, where `->required()` on one use would silently make
        // the other required too.
        $email = Field::str()->email();

        $required = $email->required();
        $bounded = $email->max(254);

        self::assertSame([EmailRule::class], self::names($email));
        self::assertSame([RequiredRule::class, EmailRule::class], self::names($required));
        self::assertSame([EmailRule::class, MaxRule::class], self::names($bounded));
    }

    public function testARefinementReplacesTheTextTypeRatherThanStackingOnIt(): void
    {
        // `->email()` is the type, not a format check beside "must be a string".
        // A second rule saying "must be a string" could never fire — the email
        // rule already refuses a non-string — so it would be dead weight in the
        // pipeline and a lie in the problem context, which names the rule that
        // actually stopped the value.
        self::assertSame([EmailRule::class], self::names(Field::str()->email()));
        self::assertSame([UuidRule::class], self::names(Field::str()->uuid()));
    }

    public function testARefinementKeepsTheRulesThatCameBeforeIt(): void
    {
        // The regression this pins: `email()` used to be a static constructor,
        // and PHP lets a static be called through an instance — so this chain
        // returned a brand-new field and silently dropped `required()`. The
        // result was a field declared required that accepted a missing value.
        $field = Field::str()->required()->email()->max(254);

        self::assertSame(
            [RequiredRule::class, EmailRule::class, MaxRule::class],
            self::names($field),
        );
        self::assertTrue($field->rules()->isRequired());
    }

    public function testARefinementKeepsABoundAStringFieldAlreadyDecided(): void
    {
        // `min(2)` was built as a CHARACTER bound because the type was text, and
        // refining the type to email must not turn it into a magnitude bound or
        // move it behind the rule that reads it.
        $rules = Field::str()->min(2)->email()->rules()->rules();

        self::assertSame([EmailRule::class, MinRule::class], self::names(Field::str()->min(2)->email()));
        self::assertSame(['bound' => 2, 'of' => 'characters'], $rules[1]->expects());
    }

    public function testARefinementOnAnUntypedChainInstallsTheTypeInFront(): void
    {
        // `Field::any()` has no type to replace, so the refinement becomes the
        // type — and it goes ahead of the rules that may be assuming it, but
        // behind `required()`, which decides whether there is a value at all.
        self::assertSame([EmailRule::class], self::names(Field::any()->email()));
        self::assertSame(
            [RequiredRule::class, EmailRule::class, InRule::class],
            self::names(Field::any()->required()->email()->in(['a'])),
        );
    }

    public function testARefinementOnANumericChainIsRefused(): void
    {
        // `Field::int()->email()` has two readings and neither is safe: apply it
        // and an integer is now an email address, or replace the type and the
        // bounds built on `int` silently lose their meaning.
        //
        // The name in the message is the RULE's stable id ('integer'), not the
        // builder method — the same string the problem context reports as
        // `rule`, which is what an agent reads back.
        try {
            Field::int()->min(18)->email();
            self::fail('a text refinement on a numeric chain should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString("this chain's type is 'integer'", $problem->getMessage());
            self::assertStringContainsString('Field::str()->email()', $problem->fix);
            self::assertSame(['format' => 'email', 'type' => 'integer'], $problem->context);
        }
    }

    public function testARefinementOnABooleanChainIsRefused(): void
    {
        $this->expectException(InvalidRule::class);

        Field::bool()->uuid();
    }

    public function testAStringBoundIsALengthBound(): void
    {
        // `min(2)` against '5' is either two characters or the number two, and
        // the value cannot say which. The type decides, before any value exists.
        $rules = Field::str()->min(2)->max(20)->rules()->rules();

        self::assertInstanceOf(MinRule::class, $rules[1]);
        self::assertSame(['bound' => 2, 'of' => 'characters'], $rules[1]->expects());
        self::assertInstanceOf(MaxRule::class, $rules[2]);
        self::assertSame(['bound' => 20, 'of' => 'characters'], $rules[2]->expects());
    }

    /** @return array<string, array{Field}> */
    public static function stringishFields(): array
    {
        return [
            'str' => [Field::str()],
            'email' => [Field::str()->email()],
            'uuid' => [Field::str()->uuid()],
        ];
    }

    #[DataProvider('stringishFields')]
    public function testEveryStringIshTypeGetsALengthBound(Field $field): void
    {
        $rules = $field->min(3)->rules()->rules();

        self::assertInstanceOf(MinRule::class, $rules[array_key_last($rules)]);
        self::assertSame(['bound' => 3, 'of' => 'characters'], $rules[array_key_last($rules)]->expects());
    }

    /** @return array<string, array{Field}> */
    public static function numericFields(): array
    {
        return ['int' => [Field::int()], 'float' => [Field::float()]];
    }

    #[DataProvider('numericFields')]
    public function testEveryNumericTypeGetsAValueBound(Field $field): void
    {
        $rules = $field->min(18)->rules()->rules();

        self::assertInstanceOf(MinRule::class, $rules[array_key_last($rules)]);
        self::assertSame(['bound' => 18, 'of' => 'value'], $rules[array_key_last($rules)]->expects());
    }

    public function testABoundOnAnUntypedChainIsRefusedRatherThanGuessed(): void
    {
        // The missing information is the type, so the fix asks for the type
        // rather than offering a workaround.
        try {
            Field::any()->min(3);
            self::fail('an untyped bound should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString('no type rule', $problem->getMessage());
            self::assertStringContainsString('Field::str()->min(3)', $problem->fix);
            self::assertStringContainsString('Field::int()->min(3)', $problem->fix);
        }
    }

    public function testABoundOnABooleanIsRefused(): void
    {
        // A boolean has no length and is not a magnitude, so there is no reading
        // of `max(1)` that means anything.
        try {
            Field::bool()->max(1);
            self::fail('a bound on a boolean should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString('means nothing', $problem->getMessage());
            self::assertStringContainsString('Field::int()', $problem->fix);
        }
    }

    public function testAFractionalLengthBoundIsRefused(): void
    {
        // Characters are counted, so `min(2.5)` cannot mean anything. Silently
        // truncating to 2 would be a bound the author never wrote.
        try {
            Field::str()->min(2.5);
            self::fail('a fractional length bound should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString('must be a whole number', $problem->getMessage());
            self::assertStringContainsString('Field::float()', $problem->fix);
        }
    }

    public function testAFractionalBoundIsFineForANumericField(): void
    {
        self::assertSame(
            ['bound' => 2.5, 'of' => 'value'],
            Field::float()->min(2.5)->rules()->rules()[1]->expects(),
        );
    }

    public function testTheChainKeepsTheOrderTheRulesWereWrittenIn(): void
    {
        // The pipeline's order is the author's, and first-failure-wins depends
        // on it: a type check must precede the bound that assumes the type.
        self::assertSame(
            [StringRule::class, MinRule::class, InRule::class],
            self::names(Field::str()->min(2)->in(['a', 'b'])),
        );
    }

    public function testTheDeclarationSiteIsTheCallersLine(): void
    {
        // A mis-declared field is found where it is written, with a file and a
        // line, rather than on whichever request reaches it first.
        try {
            Field::str()->regex('no-delimiters');
            self::fail('an undelimited pattern should have been refused');
        } catch (InvalidRule $problem) {
            self::assertNotNull($problem->source);
            self::assertSame(__FILE__, $problem->source->file);
            self::assertGreaterThan(0, $problem->source->line);
        }
    }

    public function testTheRuleSetKnowsWhetherTheFieldWasRequired(): void
    {
        // The chain's `->required()` and the rule set's `isRequired()` are the
        // same fact read from two places; a mismatch would let the validator and
        // the problem report disagree about whether an absent field is an error.
        self::assertFalse(Field::str()->max(2)->rules()->isRequired());
        self::assertTrue(Field::str()->required()->max(2)->rules()->isRequired());
        self::assertTrue(Field::any()->required()->rules()->isRequired());
    }

    /** @return list<class-string> */
    private static function names(Field $field): array
    {
        return array_map(static fn (object $rule): string => $rule::class, $field->rules()->rules());
    }
}
