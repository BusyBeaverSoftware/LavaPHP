<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Rules;

use Lava\Validate\Validation\Rules\RequiredRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The framework's single definition of "present".
 *
 * Every other rule defers to it, so a mistake here would show up as two fields
 * disagreeing about whether the same value was sent. The cases are therefore
 * exhaustive on purpose, including the ones that look obvious.
 */
final class RequiredRuleTest extends TestCase
{
    private static function rule(): RequiredRule
    {
        return new RequiredRule();
    }

    /** @return array<string, array{mixed, bool}> */
    public static function values(): array
    {
        return [
            'null is absent' => [null, false],
            'an empty string is absent' => ['', false],
            'a string of spaces is absent' => ['   ', false],
            'a tab and a newline are absent' => ["\t\n", false],
            'an empty array is absent' => [[], false],

            'zero is present' => [0, true],
            'zero as a string is present' => ['0', true],
            'false is present' => [false, true],
            'a space-wrapped zero is present' => [' 0 ', true],
            'a non-empty string is present' => ['ada', true],
            'a non-empty array is present' => [[0], true],
            'a float is present' => [0.0, true],
        ];
    }

    #[DataProvider('values')]
    public function testPresenceIsDecidedByOneDefinition(mixed $value, bool $expected): void
    {
        self::assertSame($expected, RequiredRule::isPresent($value));
        self::assertSame(
            $expected,
            self::rule()->inspect('field', $value) === null,
            'inspect() must agree with isPresent() — the rule set decides whether to run anything at all from the static, so a disagreement would let a rule run on an absent value',
        );
    }

    public function testAnEmptyValueSaysTheValueIsEmptyNotThatItWasMissing(): void
    {
        // The two fixes differ, and so do the messages: a caller who sent `''`
        // needs "send a value", a caller who sent nothing needs "include the
        // key". Getting this wrong sends the reader to the wrong place.
        $refusal = self::rule()->inspect('email', '');

        self::assertNotNull($refusal);
        self::assertStringContainsString('sent empty', $refusal->message);
        self::assertStringNotContainsString('was not sent', $refusal->message);
    }

    public function testTheExpectationIsMachineReadable(): void
    {
        // `expects` travels into a validation_failed problem's context, where an
        // agent reading JSON should not have to infer "must be present" from null.
        self::assertSame(['present' => true], self::rule()->expects());
    }
}
