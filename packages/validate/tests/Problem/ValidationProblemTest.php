<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Problem;

use Lava\Core\Config\Secrets;
use Lava\Validate\Problem\UnreadableField;
use Lava\Validate\Problem\ValidationFailed;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\RuleFailure;
use Lava\Validate\Validation\Rules\BoolRule;
use Lava\Validate\Validation\Rules\MinRule;
use Lava\Validate\Validation\RuleViolation;
use Lava\Validate\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two problems a request can produce, and the one field that can leak.
 *
 * `validation_failed` is the only problem in the framework that is the caller's
 * fault, which is the whole reason a pack can declare its own HTTP status. What
 * matters about it beyond the status is its `context`: `field`, `rule`,
 * `expects`, `value`, `sent` is the complete diagnosis, and an agent that reads
 * it can fix the request without opening any documentation.
 *
 * The redaction rule is the part worth testing hardest. `value` is the only
 * field that can hold something sensitive, and a 422 body is logged, echoed into
 * terminals and pasted into bug reports — so a password that arrives in a
 * failure message is a password in a ticket. The decision is made on the FIELD
 * NAME, which is the only signal that exists before the value is interpreted,
 * and both problems share the one implementation so they cannot drift.
 */
final class ValidationProblemTest extends TestCase
{
    private static function failure(string $field, string $value): RuleFailure
    {
        $rule = MinRule::length(12);
        $violation = $rule->inspect($field, $value);

        self::assertNotNull($violation, 'the fixture value should fail the rule');

        return new RuleFailure($rule, $violation);
    }

    public function testAFieldFailureCarriesTheWholeDiagnosis(): void
    {
        $problem = ValidationFailed::of('password', self::failure('password', 'short'), 'short', true);

        self::assertSame('validation_failed', $problem->code());
        self::assertSame(422, $problem->httpStatus());
        self::assertSame(
            ['field', 'rule', 'expects', 'value', 'sent'],
            array_keys($problem->context),
        );
        self::assertSame('password', $problem->context['field']);
        self::assertSame('min', $problem->context['rule']);
        self::assertSame(['bound' => 12, 'of' => 'characters'], $problem->context['expects']);
        self::assertTrue($problem->context['sent']);
    }

    public function testTheMessageAndFixComeFromTheRuleThatFailed(): void
    {
        // One place decides what a rule's refusal says, so the problem is a
        // carrier rather than a second author of the same sentence.
        $failure = self::failure('password', 'short');
        $problem = ValidationFailed::of('password', $failure, 'short', true);

        self::assertSame($failure->violation->message, $problem->getMessage());
        self::assertSame($failure->violation->fix, $problem->fix);
    }

    public function testASecretShapedFieldNeverShowsItsValue(): void
    {
        // The value here is short and harmless, and it is still hidden — the
        // decision is about the field, not about how the value looks, because
        // "does this look secret" is not a question a validator can answer.
        $problem = ValidationFailed::of('password', self::failure('password', 'short'), 'short', true);

        self::assertSame('<redacted>', $problem->context['value']);
        self::assertStringNotContainsString('short', json_encode($problem->json()) ?: '');
    }

    /** @return array<string, array{string}> */
    public static function secretShapedNames(): array
    {
        return [
            'password' => ['password'],
            'a compound name' => ['db_password'],
            'an uppercased name' => ['API_KEY'],
            'a hyphenated name' => ['api-key'],
            'a nested-sounding name' => ['credentials.token'],
        ];
    }

    #[DataProvider('secretShapedNames')]
    public function testEverySpellingOfASecretNameIsRedacted(string $field): void
    {
        self::assertTrue(Secrets::looksSecret($field), 'the fixture name should look secret');

        $problem = ValidationFailed::of($field, self::failure($field, 'short'), 'short', true);

        self::assertSame('<redacted>', $problem->context['value']);
    }

    public function testAnOrdinaryFieldShowsWhatWasSent(): void
    {
        // Redaction that fired on everything would make the context useless;
        // the point of the diagnosis is that an agent can see the bad value.
        $problem = ValidationFailed::of('nickname', self::failure('nickname', 'sh'), 'sh', true);

        self::assertSame('sh', $problem->context['value']);
    }

    public function testTheValueIsReportedInAFormTheEnvelopeCanCarry(): void
    {
        // The context is embedded in JSON, so an array has to become text and a
        // non-encodable value has to become its type name. Both are still the
        // diagnosis: "you sent an array" and "you sent a resource" are the
        // whole story for a rule that wanted a scalar.
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        $array = ValidationFailed::reportable('tags', ['a', 'b']);
        $object = ValidationFailed::reportable('tags', new \stdClass());
        $resourceReport = ValidationFailed::reportable('tags', $resource);

        fclose($resource);

        self::assertSame('["a","b"]', $array);
        self::assertSame('stdClass', $object);
        self::assertSame('resource (stream)', $resourceReport);
    }

    public function testAScalarIsReportedAsItselfWhateverItsType(): void
    {
        self::assertSame(0, ValidationFailed::reportable('n', 0));
        self::assertSame(false, ValidationFailed::reportable('n', false));
        self::assertSame(1.5, ValidationFailed::reportable('n', 1.5));
        self::assertNull(ValidationFailed::reportable('n', null));
    }

    public function testAnArrayThatCannotBeEncodedFallsBackToItsType(): void
    {
        // A deeply nested array can exhaust the encoder. Falling back to the
        // type keeps the context JSON-safe, which is the property the envelope
        // depends on — an unencodable context would break the whole response.
        $deep = [];
        $cursor = &$deep;
        for ($i = 0; $i < 600; $i++) {
            $cursor = [$cursor];
            $cursor = &$cursor[0];
        }
        unset($cursor);

        $reported = ValidationFailed::reportable('tags', $deep);

        self::assertSame('array', $reported);
    }

    public function testBothProblemsShareOneRedactionRule(): void
    {
        // `UnreadableField` reports the value it could not read, and it must
        // hide a password for the same reason — a redaction policy living in two
        // places would eventually hide one and print the other.
        $problem = UnreadableField::notCoercible('password', 'int', 'hunter2');

        self::assertSame('<redacted>', $problem->context['value']);
    }

    public function testTheTwoReasonsAFieldIsUnreadableAreDistinguishable(): void
    {
        $absent = UnreadableField::absent('nickname', 'string');
        $notCoercible = UnreadableField::notCoercible('nickname', 'int', 'ada');

        self::assertSame('unreadable_field', $absent->code());
        self::assertSame('unreadable_field', $notCoercible->code());
        self::assertSame(500, $absent->httpStatus());
        self::assertSame(500, $notCoercible->httpStatus());
        self::assertSame('absent', $absent->context['why']);
        self::assertSame('not_coercible', $notCoercible->context['why']);
        self::assertNotSame($absent->fix, $notCoercible->fix);
    }

    public function testARequiredFieldThatWasNotSentExplainsTheKey(): void
    {
        // End to end through the validator, so the presence facts the problem
        // reports are the ones the rule set actually decided.
        $problem = Validator::of(['email' => Field::str()->required()])
            ->validate([])
            ->problems()[0];

        self::assertSame('required', $problem->context['rule']);
        self::assertSame(['present' => true], $problem->context['expects']);
        self::assertFalse($problem->context['sent']);
        self::assertNull($problem->context['value']);
    }

    public function testAnInRuleReportsTheSetItWanted(): void
    {
        // `expects` is what makes the context machine-readable: an agent reads
        // the allowed set from the problem instead of guessing it from prose.
        $problem = Validator::of(['role' => Field::any()->required()->in(['editor', 'admin'])])
            ->validate(['role' => 'root'])
            ->problems()[0];

        self::assertSame('in', $problem->context['rule']);
        self::assertSame(['editor', 'admin'], $problem->context['expects']);
        self::assertSame('root', $problem->context['value']);
    }

    public function testAProblemBuiltFromAHandwrittenViolationStillWorks(): void
    {
        // The constructor is public and takes a RuleFailure, so a future rule
        // outside this pack can produce the same problem without this class
        // knowing anything about it.
        $failure = new RuleFailure(
            new BoolRule(),
            new RuleViolation("'terms' must be accepted.", "Send 'terms' as true."),
        );
        $problem = ValidationFailed::of('terms', $failure, 'maybe', true);

        self::assertSame("'terms' must be accepted.", $problem->getMessage());
        self::assertSame('boolean', $problem->context['rule']);
        self::assertSame(422, $problem->httpStatus());
    }

    public function testARuleWithNoParameterReportsNoExpectation(): void
    {
        // Null is the honest answer for a rule that compares against nothing —
        // "must be a boolean" has no parameter to report. An invented `expects`
        // would be a fabricated requirement an agent would try to satisfy.
        $failure = new RuleFailure(
            new BoolRule(),
            new RuleViolation("'terms' must be a boolean.", "Send 'terms' as true or false."),
        );

        self::assertNull($failure->rule->expects());
        self::assertNull(ValidationFailed::of('terms', $failure, 'maybe', true)->context['expects']);
    }
}
