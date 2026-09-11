<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Rules;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Tests\Support\Inspect;
use Lava\Validate\Validation\Rules\MaxRule;
use Lava\Validate\Validation\Rules\MinRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two bound rules, in both of their modes.
 *
 * `min(2)` against the string `'5'` is either "at least two characters" (false)
 * or "at least the number two" (true), and no inspection of the value can tell
 * which the author meant. So the mode is chosen by a named constructor and
 * fixed before any value arrives — `MinRule::length()` or `MinRule::numeric()` —
 * and this class tests that each mode means exactly one thing.
 *
 * The multibyte cases are here rather than in a footnote because they are the
 * difference between a length bound being about text and being about bytes: a
 * byte bound accepts "Jose" at `max(4)` and rejects "José", which makes the
 * rule a rule about the alphabet.
 */
final class BoundRulesTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function lengths(): array
    {
        return [
            'one short' => ['ad', false],
            'exactly at the bound' => ['ada', true],
            'one over' => ['adaa', true],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('lengths')]
    public function testMinLengthCountsCharacters(string $value, bool $expected): void
    {
        $rule = MinRule::length(3);

        self::assertSame($expected, $rule->inspect('nickname', $value) === null);
    }

    public function testMinLengthCountsCharactersNotBytes(): void
    {
        // 'José' is four characters and five bytes. A byte bound would refuse it
        // at min(4) while accepting 'Jose', so the rule would silently be about
        // the alphabet rather than about the text.
        self::assertSame(4, mb_strlen('José'));
        self::assertSame(5, strlen('José'));

        Inspect::accepts(MinRule::length(4), 'José');
        Inspect::refuses(MinRule::length(5), 'José');
    }

    public function testMinLengthNamesTheLengthItGotNotTheValue(): void
    {
        // The count is derived from the value but does not reveal it — and it is
        // the one number that makes "too short" actionable.
        $refusal = Inspect::refuses(MinRule::length(12), 'short', 'password');

        self::assertStringContainsString('at least 12 characters', $refusal->message);
        self::assertStringContainsString('5 were sent', $refusal->message);
        self::assertStringContainsString("Send 'password' with at least 12 characters", $refusal->fix);
    }

    public function testMaxLengthCountsCharactersNotBytes(): void
    {
        Inspect::accepts(MaxRule::length(4), 'José');
        Inspect::refuses(MaxRule::length(3), 'José');
    }

    /** @return array<string, array{mixed, bool}> */
    public static function numbers(): array
    {
        return [
            'one below' => [17, false],
            'exactly at the bound' => [18, true],
            'one above' => [19, true],
            'a numeric string below' => ['17', false],
            'a numeric string at the bound' => ['18', true],
            'a float below' => [17.5, false],
            'a float at the bound' => [18.0, true],
            'a non-numeric string' => ['eighteen', false],
            'null' => [null, false],
            'an array' => [[18], false],
        ];
    }

    #[DataProvider('numbers')]
    public function testMinNumericComparesMagnitude(mixed $value, bool $expected): void
    {
        self::assertSame($expected, MinRule::numeric(18)->inspect('age', $value) === null);
    }

    public function testMinNumericSaysValueRatherThanCharacters(): void
    {
        // The `of` field in `expects` is what makes the two modes machine-readable
        // for an agent reading the problem context.
        self::assertSame(['bound' => 18, 'of' => 'value'], MinRule::numeric(18)->expects());
        self::assertSame(['bound' => 3, 'of' => 'characters'], MinRule::length(3)->expects());

        $refusal = Inspect::refuses(MinRule::numeric(18), 12, 'age');

        self::assertStringContainsString('must be at least 18', $refusal->message);
        self::assertStringNotContainsString('characters', $refusal->message);
    }

    public function testMaxNumericComparesMagnitude(): void
    {
        Inspect::accepts(MaxRule::numeric(120), 120);
        Inspect::accepts(MaxRule::numeric(120), '120');
        Inspect::accepts(MaxRule::numeric(120), 119.5);
        Inspect::refuses(MaxRule::numeric(120), 121);
        Inspect::refuses(MaxRule::numeric(120), '121');
    }

    public function testTheBoundIsInclusive(): void
    {
        // "At least 18" means 18 passes. An exclusive bound would be a different
        // rule with a different name, and off-by-one here is the difference
        // between accepting and refusing a legal value.
        Inspect::accepts(MinRule::numeric(18), 18);
        Inspect::accepts(MaxRule::numeric(18), 18);
        Inspect::accepts(MinRule::length(3), 'ada');
        Inspect::accepts(MaxRule::length(3), 'ada');
    }

    public function testNegativeBoundsAreRefusedForLength(): void
    {
        // No string has a negative length, so the rule could never pass. Caught
        // where it is written.
        try {
            MinRule::length(-1);
            self::fail('a negative length should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString('negative length (-1)', $problem->getMessage());
            self::assertStringContainsString('->min(0)', $problem->fix);
        }
    }

    public function testNegativeBoundsAreAllowedForNumbers(): void
    {
        // A numeric bound has no such problem: -40 is an ordinary temperature.
        Inspect::accepts(MinRule::numeric(-40), -40);
        Inspect::refuses(MinRule::numeric(-40), -41);
    }

    public function testBothRulesNameThemselvesForTheProblemContext(): void
    {
        self::assertSame('min', MinRule::length(1)->name());
        self::assertSame('max', MaxRule::numeric(1)->name());
    }
}
