<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Rules;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Tests\Support\Inspect;
use Lava\Validate\Validation\Rules\EmailRule;
use Lava\Validate\Validation\Rules\InRule;
use Lava\Validate\Validation\Rules\RegexRule;
use Lava\Validate\Validation\Rules\UuidRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape rules: email, uuid, regex, and set membership.
 *
 * These four share a property the type rules do not have — they can be
 * MIS-DECLARED. A `->regex()` pattern can fail to compile and an `->in()` set
 * can be empty, and both are mistakes in the app's source that must be caught
 * where they are written. So this class tests the build-time refusals as
 * carefully as the run-time ones.
 */
final class FormatRulesTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function emails(): array
    {
        return [
            'a plain address' => ['ada@example.com', true],
            'a plus-tagged address' => ['ada+lists@example.com', true],
            'a subdomain' => ['ada@mail.example.co.uk', true],
            // PHP's own grammar is stricter than RFC 5321 here, and the rule
            // inherits that: a quoted local part with a space is refused. Worth
            // stating, because it is the kind of difference that looks like a
            // bug in the rule until you check whose grammar it is using.
            'a quoted local part with a space' => ['"ada lovelace"@example.com', false],
            'no at sign' => ['ada.example.com', false],
            'two at signs' => ['ada@@example.com', false],
            'no domain' => ['ada@', false],
            'a space inside' => ['ada lovelace@example.com', false],
            'a bare word' => ['ada', false],
            'a number' => [42, false],
            'an array' => [[], false],
        ];
    }

    #[DataProvider('emails')]
    public function testEmailAcceptsWhatPhpAccepts(mixed $value, bool $expected): void
    {
        // The rule delegates to filter_var, so this asserts the delegation
        // rather than re-implementing the grammar in a test.
        self::assertSame($expected, (new EmailRule())->inspect('email', $value) === null);
    }

    /** @return array<string, array{mixed, bool}> */
    public static function uuids(): array
    {
        return [
            'a v4 uuid' => ['3f2504e0-4f89-41d3-9a0c-0305e82c3301', true],
            'uppercase' => ['3F2504E0-4F89-41D3-9A0C-0305E82C3301', true],
            'a nil uuid' => ['00000000-0000-0000-0000-000000000000', true],
            'braced' => ['{3f2504e0-4f89-41d3-9a0c-0305e82c3301}', false],
            'a urn' => ['urn:uuid:3f2504e0-4f89-41d3-9a0c-0305e82c3301', false],
            'no hyphens' => ['3f2504e04f8941d39a0c0305e82c3301', false],
            'too short' => ['3f2504e0-4f89-41d3-9a0c-0305e82c330', false],
            'a non-hex digit' => ['3f2504e0-4f89-41d3-9a0c-0305e82c33zz', false],
            'a number' => [42, false],
        ];
    }

    #[DataProvider('uuids')]
    public function testUuidAcceptsOnlyTheCanonicalSpelling(mixed $value, bool $expected): void
    {
        // The brace and urn forms are legal spellings of the same value, and
        // accepting both would let a column hold two strings for one id.
        self::assertSame($expected, (new UuidRule())->inspect('id', $value) === null);
    }

    public function testTheUuidFixNamesTheFormToSend(): void
    {
        $refusal = Inspect::refuses(new UuidRule(), 'urn:uuid:3f2504e0-4f89-41d3-9a0c-0305e82c3301');

        self::assertStringContainsString('no braces, no urn: prefix', $refusal->fix);
    }

    /** @return array<string, array{mixed, bool}> */
    public static function regexSubjects(): array
    {
        return [
            'digits' => ['42', true],
            'digits with a trailing letter' => ['42a', false],
            'digits with a leading letter' => ['a42', false],
            'an empty string' => ['', false],
            'a number' => [42, false],
        ];
    }

    #[DataProvider('regexSubjects')]
    public function testRegexAnchorsThePatternForYou(mixed $value, bool $expected): void
    {
        // `'/\d+/'` means "the whole value is digits". Unanchored matching is
        // the single most common validation bug: `'/\d+/'` would accept 'abc5'.
        self::assertSame($expected, (new RegexRule('/\d+/'))->inspect('n', $value) === null);
    }

    public function testAnchoringIsIdempotent(): void
    {
        // `'/^\d+$/'` and `'/\d+/'` must produce the same pattern — a caller who
        // anchored by hand must not get `^` twice, which is a valid pattern
        // that matches nothing.
        self::assertSame(
            (new RegexRule('/\d+/'))->expects(),
            (new RegexRule('/^\d+$/'))->expects(),
        );
        self::assertSame('/^\d+$/D', (new RegexRule('/\d+/'))->expects());
    }

    public function testTheExpectationIsTheAnchoredPattern(): void
    {
        // The problem context shows what was expected; for a regex that is the
        // pattern as it will actually be applied, not as the caller typed it —
        // including the `D` that makes `$` the end of the value.
        self::assertSame('/^[a-z]+$/D', (new RegexRule('/[a-z]+/'))->expects());
    }

    public function testATrailingNewlineIsNotTheWholeValue(): void
    {
        // PCRE's `$` also matches immediately before a final newline, so every
        // `->regex()` in every app accepted a value with one on the end — a
        // slug that validated and then split a log line (security review).
        $rule = new RegexRule('/[a-z0-9-]+/');

        Inspect::accepts($rule, 'my-slug');
        Inspect::refuses($rule, "my-slug\n");
        self::assertStringEndsWith('D', $rule->expects());

        // `m` is a deliberate request for `$` to mean the end of a LINE, and is
        // left as the caller wrote it.
        $multiline = new RegexRule('/^[a-z]+$/m');
        self::assertSame('/^[a-z]+$/m', $multiline->expects());
        Inspect::accepts($multiline, "abc\n");
    }

    public function testAnUnusablePatternIsRefusedWhenItIsWritten(): void
    {
        // Not on the first request that reaches the field. A `preg_match`
        // warning on request 4,000 is the same bug reported in the least useful
        // possible way.
        $this->expectException(InvalidRule::class);

        new RegexRule('/a(b/');
    }

    public function testTheRefusalQuotesTheRealPcreComplaint(): void
    {
        // `preg_last_error_msg()` reports every compile failure as "Internal
        // error", which tells the author nothing; the warning names the defect.
        try {
            new RegexRule('/a(b/');
            self::fail('an unusable pattern should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString('missing closing parenthesis', $problem->getMessage());
            self::assertStringNotContainsString('Internal error', $problem->getMessage());
        }
    }

    public function testAMissingDelimiterIsExplainedRatherThanGuessed(): void
    {
        // `'[a-z]+'` is the trap: PCRE itself would accept `[` as a delimiter
        // and read the `+` as a modifier, so the honest answer is "this is not
        // what you meant", not PCRE's complaint about a character the author
        // never typed. Anchoring before parsing is what would produce that
        // complaint, which is why the pattern is parsed first.
        try {
            new RegexRule('[a-z]+');
            self::fail('a pattern without delimiters should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString('has no delimiters', $problem->getMessage());
            self::assertStringContainsString("'/[a-z]+/'", $problem->fix);
            self::assertStringNotContainsString('Unknown modifier', $problem->getMessage());
        }
    }

    public function testModifiersSurviveAnchoring(): void
    {
        // A pattern is a delimiter, a body and optional modifiers. Slicing the
        // body as "everything between the first and last character" turns
        // `'/abc/i'` into `/^abc/$/` and refuses a pattern that was fine.
        $rule = new RegexRule('/ABC/i');

        self::assertSame('/^ABC$/iD', $rule->expects());
        Inspect::accepts($rule, 'abc');
        Inspect::refuses($rule, 'abcde');
    }

    public function testADelimiterInsideTheBodyIsNotMistakenForTheEnd(): void
    {
        // The closing delimiter is the LAST one that leaves only modifiers after
        // it, so a body containing the delimiter still parses.
        $rule = new RegexRule('/a\/b/');

        self::assertSame('/^a\/b$/D', $rule->expects());
        Inspect::accepts($rule, 'a/b');
    }

    public function testABracketDelimiterIsPairedWithItsOwnCloser(): void
    {
        // PCRE allows `(…)`, `[…]`, `{…}` and `<…>` as delimiters, where the
        // closer is not the same character as the opener.
        $rule = new RegexRule('{[a-z]+}');

        self::assertSame('{^[a-z]+$}D', $rule->expects());
        Inspect::accepts($rule, 'ada');
        Inspect::refuses($rule, 'ada1');
    }

    /** @return array<string, array{mixed, bool}> */
    public static function roles(): array
    {
        return [
            'a form string' => ['editor', true],
            'a JSON string' => ['admin', true],
            'an int against a string set' => [18, true],
            'a string against an int set' => ['18', true],
            'a float against an int set' => [18.0, true],
            'a boolean against a one-entry set' => [true, true],
            'not in the set' => ['root', false],
            'a number not in the set' => [19, false],
            'null' => [null, false],
            'an array' => [['editor'], false],
        ];
    }

    #[DataProvider('roles')]
    public function testInComparesByStringForm(mixed $value, bool $expected): void
    {
        // A form sends '18' where JSON sends 18. Comparing identity would make
        // the same request succeed or fail depending on its content type.
        $rule = new InRule([18, 'editor', 'admin', '21', true]);

        self::assertSame($expected, $rule->inspect('f', $value) === null);
    }

    public function testTheInFixListsTheWholeSet(): void
    {
        $refusal = Inspect::refuses(new InRule(['draft', 'published']), 'archived');

        self::assertStringContainsString('draft, published', $refusal->fix);
        self::assertSame(['draft', 'published'], (new InRule(['draft', 'published']))->expects());
    }

    public function testAnEmptyAllowedSetIsRefused(): void
    {
        // A rule nothing can satisfy is a declaration mistake, and the caller
        // almost certainly meant to build the list first.
        try {
            new InRule([]);
            self::fail('an empty allowed set should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString('no value could ever pass', $problem->getMessage());
            self::assertStringContainsString('build it before declaring the field', $problem->fix);
        }
    }
}
