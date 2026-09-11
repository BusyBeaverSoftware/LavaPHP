<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Rules;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Tests\Support\Inspect;
use Lava\Validate\Validation\Rules\CustomRule;
use PHPUnit\Framework\TestCase;

/**
 * The escape hatch, and the two things it must never do.
 *
 * It must never invent a message — the author writes both sentences, because
 * only the author knows what the predicate tests — and it must never present a
 * broken predicate as a validation failure. The second is the one worth testing
 * hard: "your input was bad" is a confident, actionable, completely wrong
 * answer when the real fault is the app's own code.
 */
final class CustomRuleTest extends TestCase
{
    private static function rule(\Closure $check): CustomRule
    {
        return new CustomRule(
            name: 'not_a_reserved_word',
            check: $check,
            message: "'{field}' must not be a reserved word.",
            fix: "Send '{field}' as a slug that is not reserved.",
            expects: ['not_in' => ['new', 'edit']],
        );
    }

    public function testTheAuthorsMessageAndFixAreUsedVerbatim(): void
    {
        $refusal = Inspect::refuses(
            self::rule(static fn (mixed $value): bool => $value !== 'new'),
            'new',
            'slug',
        );

        self::assertSame("'slug' must not be a reserved word.", $refusal->message);
        self::assertSame("Send 'slug' as a slug that is not reserved.", $refusal->fix);
    }

    public function testTheFieldNameIsSubstitutedSoARenameCannotLeaveAStaleName(): void
    {
        // The one substitution this class performs, and the reason it exists:
        // a message that hardcoded 'slug' would keep saying 'slug' after the
        // field was renamed.
        $refusal = Inspect::refuses(
            self::rule(static fn (mixed $value): bool => false),
            'anything',
            'permalink',
        );

        self::assertStringContainsString("'permalink'", $refusal->message);
        self::assertStringNotContainsString('{field}', $refusal->message);
        self::assertStringNotContainsString('{field}', $refusal->fix);
    }

    public function testTheExpectationTravelsForAnAgentToRead(): void
    {
        self::assertSame(['not_in' => ['new', 'edit']], self::rule(static fn (): bool => true)->expects());
        self::assertNull(
            new CustomRule('x', static fn (): bool => true, 'm', 'f')->expects(),
            'a predicate with no declared expectation must report null, not an invented one',
        );
    }

    public function testThePredicateReceivesTheValueAsItArrived(): void
    {
        // No coercion on the way in: a predicate that says `is_string($v)` must
        // see the string the caller sent, not a normalised version of it.
        $seen = null;
        self::rule(static function (mixed $value) use (&$seen): bool {
            $seen = $value;

            return true;
        })->inspect('slug', 'ada-lovelace');

        self::assertSame('ada-lovelace', $seen);
    }

    public function testABrokenPredicateIsAnAppFaultNotAValidationFailure(): void
    {
        $rule = self::rule(static fn (mixed $value): bool => throw new \RuntimeException('the predicate is broken'));

        try {
            $rule->inspect('slug', 'ada-lovelace');
            self::fail('a throwing predicate should have raised a problem');
        } catch (InvalidRule $problem) {
            self::assertSame('invalid_rule', $problem->code());
            self::assertStringContainsString('the predicate is broken', $problem->getMessage());
            self::assertStringContainsString('not_a_reserved_word', $problem->getMessage());
            self::assertStringContainsString('slug', $problem->getMessage());
            self::assertSame('RuntimeException', $problem->context['thrown']);
            self::assertStringContainsString('must not throw', $problem->fix);
        }
    }

    public function testTheOriginalThrowableSurvivesAsPrevious(): void
    {
        // The stack trace is the diagnosis, and a problem that swallowed it
        // would send the reader looking for a framework bug.
        $rule = self::rule(static fn (mixed $value): bool => throw new \RuntimeException('the predicate is broken'));

        try {
            $rule->inspect('slug', 'ada-lovelace');
            self::fail('a throwing predicate should have raised a problem');
        } catch (InvalidRule $problem) {
            self::assertInstanceOf(\RuntimeException::class, $problem->getPrevious());
            self::assertSame('the predicate is broken', $problem->getPrevious()?->getMessage());
        }
    }

    public function testTheProblemIsA500BecauseTheCallerDidNothingWrong(): void
    {
        // The status is the observable difference between "fix your request" and
        // "fix your app", and an agent that retries on a 422 would retry this
        // forever.
        $rule = self::rule(static fn (mixed $value): bool => throw new \RuntimeException('broken'));

        try {
            $rule->inspect('slug', 'ada-lovelace');
            self::fail('a throwing predicate should have raised a problem');
        } catch (InvalidRule $problem) {
            self::assertSame(500, $problem->httpStatus());
        }
    }

    public function testAPredicateThatThrowsIsNotReportedAsARefusal(): void
    {
        // The distinction the whole class exists for: the value was fine, so
        // there must be no RuleViolation and no 'must not be a reserved word'.
        $rule = self::rule(static fn (mixed $value): bool => throw new \ValueError('broken'));

        try {
            $rule->inspect('slug', 'ada-lovelace');
            self::fail('a throwing predicate should have raised a problem');
        } catch (InvalidRule $problem) {
            self::assertStringNotContainsString('reserved word', $problem->getMessage());
            self::assertStringNotContainsString('ada-lovelace', $problem->getMessage());
        }
    }

    public function testAnErrorIsCaughtAsWellAsAnException(): void
    {
        // A predicate calling a method that does not exist throws an Error, not
        // an Exception. Catching only Exception would let a broken predicate
        // escape as an uncatchable-by-the-app 500 with no fix text.
        $rule = self::rule(static fn (mixed $value): bool => (static function (): mixed {
            throw new \Error('call to an undefined method');
        })());

        $this->expectException(InvalidRule::class);

        $rule->inspect('slug', 'ada-lovelace');
    }
}
