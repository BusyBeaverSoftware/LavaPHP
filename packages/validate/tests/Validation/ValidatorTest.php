<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Validation;

use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one method that runs a field map against a payload.
 *
 * Three properties carry most of the weight here, and each of them is a
 * decision someone could reasonably get wrong:
 *
 * 1. EVERY field is checked. A form with four bad fields produces four problems
 *    so the caller can fix them in one round trip.
 * 2. ONE problem per field. A field that fails its type rule and would also
 *    fail a bound reports the type only, because "send a number" is the fix and
 *    "and it is also below 18" is a complaint about a value that does not exist.
 * 3. Presence is read from the PAYLOAD'S KEYS, not from the value, so "you did
 *    not send the key" and "you sent null" stay distinguishable all the way to
 *    the fix text.
 */
final class ValidatorTest extends TestCase
{
    /** @return array<string, Field> */
    private static function registration(): array
    {
        return [
            'email' => Field::str()->required()->email(),
            'age' => Field::int()->required()->min(18),
            'nickname' => Field::str()->max(20),
        ];
    }

    public function testEveryFieldIsCheckedNotJustTheFirstThatFails(): void
    {
        // Failing fast would turn one request into three: the caller fixes the
        // email, resends, and learns about the age.
        $input = Validator::of(self::registration())->validate([
            'email' => 'not-an-email',
            'age' => 12,
        ]);

        self::assertTrue($input->failed());
        self::assertCount(2, $input->problems());
        self::assertSame(['email', 'age'], array_map(
            static fn ($problem): mixed => $problem->context['field'],
            $input->problems(),
        ));
    }

    public function testOneProblemPerFieldEvenWhenSeveralRulesWouldFail(): void
    {
        // 'abc' fails the int rule AND would fail min(18). Only the type is
        // reported: the min comparison never ran, and reporting it would be a
        // second, meaningless complaint about a comparison that did not happen.
        $input = Validator::of(['age' => Field::int()->required()->min(18)])->validate(['age' => 'abc']);

        self::assertCount(1, $input->problems());
        self::assertSame('integer', $input->problems()[0]->context['rule']);
        self::assertStringNotContainsString('18', $input->problems()[0]->getMessage());
    }

    public function testProblemsComeBackInDeclarationOrder(): void
    {
        // The order is the author's, so a response body reads like the form.
        $input = Validator::of([
            'zebra' => Field::int()->required(),
            'alpha' => Field::int()->required(),
            'middle' => Field::int()->required(),
        ])->validate(['zebra' => 'x', 'alpha' => 'x', 'middle' => 'x']);

        self::assertSame(['zebra', 'alpha', 'middle'], array_map(
            static fn ($problem): mixed => $problem->context['field'],
            $input->problems(),
        ));
    }

    public function testAMissingKeyAndANullValueGetDifferentFixes(): void
    {
        // Both arrive as a null value. The caller who omitted the key needs
        // "include it"; the caller who sent null needs "send a value" — telling
        // them to include a key they already included is a fix that does not
        // work.
        $omitted = Validator::of(['email' => Field::str()->required()])->validate([]);
        $sentNull = Validator::of(['email' => Field::str()->required()])->validate(['email' => null]);

        self::assertFalse($omitted->problems()[0]->context['sent']);
        self::assertStringContainsString('was not sent', $omitted->problems()[0]->getMessage());
        self::assertStringContainsString('Include', $omitted->problems()[0]->fix);

        self::assertTrue($sentNull->problems()[0]->context['sent']);
        self::assertStringContainsString('was sent empty', $sentNull->problems()[0]->getMessage());
        self::assertStringContainsString('omit the key', $sentNull->problems()[0]->fix);
    }

    public function testAWhitespaceOnlyValueCountsAsSentButEmpty(): void
    {
        // The key was there, so the caller is not told to include it; the value
        // was blank, so the fix is to send something real.
        $input = Validator::of(['email' => Field::str()->required()])->validate(['email' => '   ']);

        self::assertTrue($input->problems()[0]->context['sent']);
        self::assertStringContainsString('was sent empty', $input->problems()[0]->getMessage());
    }

    /** @return array<string, array{mixed}> */
    public static function presentValues(): array
    {
        return [
            'a zero' => [0],
            'a false' => [false],
            'the string zero' => ['0'],
            'a non-empty string' => ['ada'],
            'a non-empty array' => [['ada']],
        ];
    }

    /**
     * @param mixed $value
     */
    #[DataProvider('presentValues')]
    public function testAValueTheCallerMeantToSendIsNeverTreatedAsMissing(mixed $value): void
    {
        // Treating a legitimate `0` or `false` as "missing" is the classic
        // validation bug: a boolean field set to false is not an absent field.
        $input = Validator::of(['f' => Field::any()->required()])->validate(['f' => $value]);

        self::assertTrue($input->valid(), 'a present value should not be refused as missing');
        self::assertTrue($input->has('f'));
    }

    public function testAnOptionalFieldThatIsAbsentIsSimplyNotInTheResult(): void
    {
        // `->max(20)` on an absent nickname must not fire, or "optional but
        // short" would be impossible to express.
        $input = Validator::of(['nickname' => Field::str()->max(20)])->validate([]);

        self::assertTrue($input->valid());
        self::assertFalse($input->has('nickname'));
        self::assertNull($input->value('nickname'));
    }

    public function testAnOptionalFieldThatIsBlankIsAbsentByTheSameDefinition(): void
    {
        // The rules and the result share one definition of "present". If the
        // result used `array_key_exists` on its own, this field would validate
        // as optional-and-fine and then read back as a whitespace string.
        $input = Validator::of(['nickname' => Field::str()->max(20)])->validate(['nickname' => '  ']);

        self::assertTrue($input->valid());
        self::assertFalse($input->has('nickname'));
    }

    public function testAnOptionalFieldThatIsPresentIsKept(): void
    {
        $input = Validator::of(['nickname' => Field::str()->max(20)])->validate(['nickname' => 'ada']);

        self::assertTrue($input->has('nickname'));
        self::assertSame('ada', $input->value('nickname'));
    }

    public function testUndeclaredKeysNeverReachTheResult(): void
    {
        // The field map is the allow-list, so a profile form cannot pass an
        // `is_admin` through to whatever the handler does next. This is a
        // property of the shape, not a filter someone must remember to call.
        $input = Validator::of(['nickname' => Field::str()->max(20)])->validate([
            'nickname' => 'ada',
            'is_admin' => true,
            'id' => 1,
        ]);

        self::assertSame(['nickname' => 'ada'], $input->all());
        self::assertFalse($input->has('is_admin'));
    }

    public function testAFailedFieldIsNotReadableFromTheResult(): void
    {
        // A handler that forgot to check `failed()` gets a null from `value()`
        // rather than the bad input — the two halves of the result cannot be
        // used to build half-valid data.
        $input = Validator::of(['age' => Field::int()->required()->min(18)])->validate(['age' => 12]);

        self::assertTrue($input->failed());
        self::assertFalse($input->has('age'));
        self::assertNull($input->value('age'));
    }

    public function testAPassingFieldIsKeptAlongsideAFailingOne(): void
    {
        // Both halves are available at once, which is what lets a 422 list every
        // bad field while a handler still sees what was right.
        $input = Validator::of(self::registration())->validate([
            'email' => 'ada@example.com',
            'age' => 12,
        ]);

        self::assertSame(['email' => 'ada@example.com'], $input->all());
        self::assertCount(1, $input->problems());
    }

    public function testTheValidatorHoldsNoStateBetweenPayloads(): void
    {
        // Built once and reused — in a service, or as a static on the handler.
        // A validator that remembered a request would be a validator that leaks
        // one caller's values into the next.
        $validator = Validator::of(self::registration());

        $first = $validator->validate(['email' => 'ada@example.com', 'age' => 30]);
        $second = $validator->validate(['email' => 'nope', 'age' => 'nope']);

        self::assertTrue($first->valid());
        self::assertTrue($second->failed());
        self::assertCount(2, $second->problems());
        self::assertSame(['email' => 'ada@example.com', 'age' => 30], $first->all());
    }

    public function testTheReportIsAProblemPerFailedField(): void
    {
        // The report is the object `HttpErrors::forReport()` turns into a 422,
        // so its problem list has to be the same one the caller iterates.
        $input = Validator::of(self::registration())->validate(['email' => 'nope', 'age' => 'nope']);

        $report = $input->report();

        self::assertSame($input->problems(), $report->problems());
        self::assertSame('validation_failed', $report->problems()[0]->code());
        self::assertSame(422, $report->problems()[0]->httpStatus());
    }

    public function testAnEmptyFieldMapAcceptsEverythingAndKeepsNothing(): void
    {
        // Nothing declared means nothing validated and nothing allowed through.
        // Worth pinning: it is the shape a handler with no input has, and a
        // "keeps everything" reading here would be a passthrough of the whole
        // payload.
        $input = Validator::of([])->validate(['anything' => 'at all']);

        self::assertTrue($input->valid());
        self::assertSame([], $input->all());
    }
}
