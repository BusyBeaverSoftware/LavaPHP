<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Validation;

use Lava\Validate\Problem\UnreadableField;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validated;
use Lava\Validate\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The result object, and the one rule its accessors exist to keep.
 *
 * The typed accessors are STRICT: `int('age')` returns an int or throws. The
 * alternative — returning `0` or `null` for a field that cannot be read — is the
 * most expensive failure this framework can produce, because a handler will
 * happily store the zero in a column that should have held a value. So a
 * declaration mistake is reported as a problem, once, instead of becoming a
 * silently wrong row.
 *
 * The second thing worth testing here is that reading and validating agree. Each
 * accessor delegates to the very rule that validated the field, so `'34'` from a
 * form and `34` from JSON are one decision made in one place — and the tests
 * that assert it are the tests that would catch the two drifting apart.
 */
final class ValidatedTest extends TestCase
{
    /** @param array<string, mixed> $input */
    private static function validate(array $fields, array $input): Validated
    {
        return Validator::of($fields)->validate($input);
    }

    public function testValidityIsOneDecisionReadTwoWays(): void
    {
        $ok = self::validate(['n' => Field::str()], ['n' => 'ada']);
        $bad = self::validate(['n' => Field::int()], ['n' => 'ada']);

        self::assertTrue($ok->valid());
        self::assertFalse($ok->failed());
        self::assertFalse($bad->valid());
        self::assertTrue($bad->failed());
    }

    public function testAValidResultHasNoProblemsAndAnEmptyReport(): void
    {
        $input = self::validate(['n' => Field::str()], ['n' => 'ada']);

        self::assertSame([], $input->problems());
        self::assertTrue($input->report()->isEmpty());
    }

    public function testAFormStringIsReadableAsTheNumberItHolds(): void
    {
        // A form sends '34' where JSON sends 34. Coercion happens once, in the
        // rule that already decided the value was an integer.
        $input = self::validate(['age' => Field::int()->required()], ['age' => '34']);

        self::assertTrue($input->valid());
        self::assertSame(34, $input->int('age'));
        self::assertSame(34.0, $input->float('age'));
    }

    public function testABooleanHasAFalsySpellingThatIsReadable(): void
    {
        // `false` is a readable value, not an unreadable one. An accessor that
        // treated a falsy reading as failure would make `bool('terms')` throw
        // exactly when the caller unchecked the box.
        self::assertFalse(self::validate(['t' => Field::bool()], ['t' => 'off'])->bool('t'));
        self::assertFalse(self::validate(['t' => Field::bool()], ['t' => '0'])->bool('t'));
        self::assertFalse(self::validate(['t' => Field::bool()], ['t' => false])->bool('t'));
        self::assertTrue(self::validate(['t' => Field::bool()], ['t' => 'on'])->bool('t'));
        self::assertTrue(self::validate(['t' => Field::bool()], ['t' => '1'])->bool('t'));
        self::assertTrue(self::validate(['t' => Field::bool()], ['t' => true])->bool('t'));
    }

    public function testTheValueArrivesAsItWasSent(): void
    {
        // `value()` is the raw payload value, not the coerced reading — the
        // accessors coerce, and the result object does not rewrite the input.
        $input = self::validate(['age' => Field::int()->required()], ['age' => '34']);

        self::assertSame('34', $input->value('age'));
        self::assertSame(34, $input->int('age'));
    }

    public function testReadingAFailedFieldIsRefusedRatherThanAnswered(): void
    {
        // Not reachable from a correctly-written handler, which checks
        // `failed()` first — and the refusal is what makes that check load-
        // bearing instead of decorative.
        $input = self::validate(['age' => Field::int()->required()->min(18)], ['age' => 12]);

        self::assertTrue($input->failed());
        $this->expectException(UnreadableField::class);
        $input->int('age');
    }

    /** @return array<string, array{string, string, mixed}> */
    public static function unreadableReads(): array
    {
        return [
            'string from a number' => ['string', 'n', 34],
            'int from a word' => ['int', 'n', 'ada'],
            'float from a word' => ['float', 'n', 'ada'],
            'bool from a word' => ['bool', 'n', 'ada'],
            'int from an array' => ['int', 'n', ['34']],
        ];
    }

    /**
     * @param mixed $value
     */
    #[DataProvider('unreadableReads')]
    public function testAValueThatPassedValidationButCannotBeReadIsRefused(string $accessor, string $field, mixed $value): void
    {
        // Reachable through `Field::any()`, which promises nothing about the
        // shape — so the accessor's promise is the only one left, and it is
        // enforced rather than assumed.
        $input = self::validate([$field => Field::any()], [$field => $value]);

        self::assertTrue($input->valid(), 'the value should have passed an untyped field');

        try {
            $input->{$accessor}($field);
            self::fail('reading an uncoercible value should have been refused');
        } catch (UnreadableField $problem) {
            self::assertSame('unreadable_field', $problem->code());
            self::assertSame('not_coercible', $problem->context['why']);
            self::assertSame($field, $problem->context['field']);
            self::assertSame($accessor, $problem->context['type']);
            self::assertStringContainsString(get_debug_type($value), $problem->getMessage());
            self::assertSame(500, $problem->httpStatus(), 'the app asked for the wrong type, so the caller did nothing wrong');
        }
    }

    public function testReadingAnAbsentFieldIsRefusedWithADifferentReason(): void
    {
        // The two ways to reach this problem need different fixes — declare it
        // required, or ask the type the field actually promised — so the
        // context has to say which one happened.
        $input = self::validate(['nickname' => Field::str()], []);

        try {
            $input->string('nickname');
            self::fail('reading an absent field should have been refused');
        } catch (UnreadableField $problem) {
            self::assertSame('absent', $problem->context['why']);
            self::assertStringContainsString('->required()', $problem->fix);
            self::assertStringContainsString("->has('nickname')", $problem->fix);
        }
    }

    public function testReadingAFieldThatWasNeverDeclaredIsRefused(): void
    {
        // The allow-list is enforced on the way out too: a field the validator
        // never declared is absent, not "present but undeclared".
        $input = self::validate(['n' => Field::str()], ['n' => 'ada', 'id' => 1]);

        try {
            $input->int('id');
            self::fail('reading an undeclared field should have been refused');
        } catch (UnreadableField $problem) {
            self::assertSame('absent', $problem->context['why']);
        }
    }

    /** @return array<string, array{string}> */
    public static function accessors(): array
    {
        return ['string' => ['string'], 'int' => ['int'], 'float' => ['float'], 'bool' => ['bool']];
    }

    #[DataProvider('accessors')]
    public function testEveryFixNamesABuilderMethodThatExists(string $accessor): void
    {
        // The fix text is meant to be followed, so a fix naming a method that
        // does not exist is worse than no fix at all. `->string()` reads a
        // string and `Field::str()` declares one — the accessor's word for a
        // type is not always the builder's, and this is where that shows up.
        $input = self::validate(['n' => Field::any()], ['n' => new \stdClass()]);

        try {
            $input->{$accessor}('n');
            self::fail('reading an object should have been refused');
        } catch (UnreadableField $problem) {
            preg_match_all('/Field::(\w+)\(\)/', $problem->fix, $named);
            self::assertNotEmpty($named[1], "the fix for {$accessor}() should name a builder method");

            foreach ($named[1] as $method) {
                self::assertTrue(
                    method_exists(Field::class, $method),
                    "the fix names Field::{$method}(), which does not exist",
                );
            }
        }
    }

    public function testAllReturnsEveryPresentDeclaredField(): void
    {
        $input = self::validate(
            ['email' => Field::str()->required(), 'nickname' => Field::str()],
            ['email' => 'ada@example.com', 'nickname' => 'ada'],
        );

        self::assertSame(['email' => 'ada@example.com', 'nickname' => 'ada'], $input->all());
    }

    public function testAllIsEmptyWhenNothingWasSent(): void
    {
        self::assertSame([], self::validate(['nickname' => Field::str()], [])->all());
    }

    public function testHasAgreesWithValueAboutAbsence(): void
    {
        // Two ways to ask one question. A field where `has()` is true and
        // `value()` is null would make `?? $default` silently wrong.
        $input = self::validate(['n' => Field::str()], []);

        self::assertFalse($input->has('n'));
        self::assertNull($input->value('n'));
        self::assertNull($input->value('never-declared-at-all'));
    }
}
