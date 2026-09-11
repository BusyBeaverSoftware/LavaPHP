<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Problem;

use Lava\Core\Problem\Severity;
use Lava\Core\Problem\SourceLocation;
use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Rules\EmailRule;
use Lava\Validate\Validation\Rules\IntRule;
use Lava\Validate\Validation\Rules\RegexRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The declaration-time refusals, one factory at a time.
 *
 * Every one of these is a mistake in the app's SOURCE, so the two things this
 * class checks hardest are the two that make that mistake cheap to fix: the
 * problem carries a source location pointing at the line to edit, and the fix
 * says what to write instead. A refusal without a location sends the reader
 * hunting through a field map; a refusal without a fix sends them to the docs.
 *
 * The context shape is checked here too, because it is a machine-readable
 * contract: an agent reads `context` to decide what to do, and a key that
 * silently disappears is a key an agent will guess at.
 */
final class InvalidRuleTest extends TestCase
{
    /** @return array<string, array{InvalidRule, string, string, list<string>}> */
    public static function declarations(): array
    {
        // The factories are called from HERE, so `source` must point at this
        // file — which is what the first assertion below checks for each one.
        return [
            'unusable pattern' => [
                InvalidRule::unusablePattern('/a(b/', 'missing closing parenthesis'),
                'missing closing parenthesis',
                'anchored for you',
                ['pattern', 'reason'],
            ],
            'undelimited pattern' => [
                InvalidRule::undelimited('[a-z]+'),
                '[a-z]+',
                "Wrap it in delimiters, e.g. '/[a-z]+/'",
                ['pattern'],
            ],
            'empty allowed set' => [
                InvalidRule::emptyAllowedSet(),
                'no value could ever pass',
                'build it before declaring the field',
                ['rule'],
            ],
            'negative length' => [
                InvalidRule::negativeLength('min', -1),
                'negative length (-1)',
                '->min(0)',
                ['rule', 'bound'],
            ],
            'untyped bound' => [
                InvalidRule::untypedBound('min'),
                'has no type rule',
                'Field::int()->min(3)',
                ['rule'],
            ],
            'fractional length' => [
                InvalidRule::fractionalLength('min', 2.5),
                'must be a whole number',
                'Field::float()',
                ['rule', 'bound'],
            ],
            'bound on a boolean' => [
                InvalidRule::boundOnBoolean('max'),
                'means nothing',
                'Field::int()',
                ['rule'],
            ],
            'incompatible format' => [
                InvalidRule::incompatibleFormat(new EmailRule(), new IntRule()),
                "this chain's type is 'integer'",
                'Field::str()->email()',
                ['format', 'type'],
            ],
        ];
    }

    /**
     * @param list<string> $contextKeys
     */
    #[DataProvider('declarations')]
    public function testADeclarationMistakeIsFoundWhereItIsWritten(        InvalidRule $problem,
        string $inMessage,
        string $inFix,
        array $contextKeys,
    ): void {
        self::assertSame('invalid_rule', $problem->code());
        self::assertSame(500, $problem->httpStatus(), 'the app is broken, not the caller');
        self::assertSame(Severity::Fatal, $problem->severity());
        self::assertStringContainsString($inMessage, $problem->getMessage());
        self::assertStringContainsString($inFix, $problem->fix);

        self::assertNotNull($problem->source, 'a declaration mistake must carry the line to edit');
        self::assertSame(__FILE__, $problem->source->file);
        self::assertGreaterThan(0, $problem->source->line);

        foreach ($contextKeys as $key) {
            self::assertArrayHasKey($key, $problem->context);
        }
        self::assertCount(count($contextKeys), $problem->context, 'context keys are a contract; extra ones are undeclared surface');
    }

    public function testTheProblemSerialisesForAnAgentToRead(): void
    {
        // The same shape appears in boot reports and `--json` output, so the
        // context has to survive json_encode — an object in there would make
        // the whole envelope unencodable.
        $problem = InvalidRule::untypedBound('min');

        $json = json_encode($problem->json());

        self::assertIsString($json);
        self::assertSame(
            ['code', 'problem', 'fix', 'context', 'source', 'severity'],
            array_keys($problem->json()),
        );
    }

    public function testTheUndelimitedFixDoesNotNameAMethodThatDoesNotExist(): void
    {
        // The fix text is meant to be followed literally, so a fix that names a
        // builder method is checked against the builder — the same invariant
        // `Validated`'s accessors are held to.
        foreach (self::declarations() as [$problem]) {
            preg_match_all('/Field::(\w+)\(\)/', $problem->fix, $named);

            foreach ($named[1] as $method) {
                self::assertTrue(
                    method_exists(Field::class, $method),
                    "the fix names Field::{$method}(), which does not exist",
                );
            }
        }
    }

    public function testABrokenPredicatePointsAtTheChainNotAtTheFramework(): void
    {
        // Raised from `inspect()`, long after the chain was built, so a stack
        // walk here would report the framework's own handler invocation as the
        // app's mistake. The rule captured its declaration site when it was
        // constructed; that is the location that gets used.
        $declaredAt = SourceLocation::of('/app/Http/RegisterController.php', 42);
        $problem = InvalidRule::predicateFailed(
            'not_reserved',
            'slug',
            new \RuntimeException('the predicate is broken'),
            $declaredAt,
        );

        self::assertSame('/app/Http/RegisterController.php', $problem->source?->file);
        self::assertSame(42, $problem->source?->line);
        self::assertSame('/app/Http/RegisterController.php:42', (string) $problem->source);
    }

    public function testABrokenPredicateWithNoRecordedSiteReportsNoSourceRatherThanAWrongOne(): void
    {
        // The fallback matters: a source that pointed at the framework would be
        // worse than no source at all, because it would send the reader to edit
        // a file that is not theirs and is not wrong.
        $problem = InvalidRule::predicateFailed('x', 'f', new \RuntimeException('broken'));

        self::assertNull($problem->source);
        self::assertNull($problem->json()['source']);
    }

    public function testTheBrokenPredicateKeepsItsThrowable(): void
    {
        $thrown = new \RuntimeException('the predicate is broken');
        $problem = InvalidRule::predicateFailed('not_reserved', 'slug', $thrown);

        self::assertSame($thrown, $problem->getPrevious());
        self::assertStringContainsString('RuntimeException', $problem->getMessage());
        self::assertStringContainsString('the predicate is broken', $problem->getMessage());
        self::assertSame('RuntimeException', $problem->context['thrown']);
        self::assertSame('slug', $problem->context['field']);
        self::assertSame('not_reserved', $problem->context['rule']);
    }

    public function testARuleRefusalAndARuleMistakeAreDifferentProblems(): void
    {
        // The distinction the pack turns on. A bad value is a 422 the caller
        // fixes; a broken rule is a 500 the developer fixes. Reporting the
        // second as the first sends the caller to edit a value that was fine.
        $mistake = InvalidRule::untypedBound('min');

        self::assertSame('invalid_rule', $mistake->code());
        self::assertNotSame('validation_failed', $mistake->code());
        self::assertSame(500, $mistake->httpStatus());
    }

    public function testThePatternIsDiagnosedAsWrittenNotAsRewritten(): void
    {
        // The anchoring step rewrites a pattern before PCRE sees it, so a
        // pattern with no delimiters would be reported as a complaint about a
        // `$` the author never typed. The parse happens first, which is what
        // makes this message about the text the author actually wrote.
        try {
            new RegexRule('[a-z]+');
            self::fail('a pattern with no delimiters should have been refused');
        } catch (InvalidRule $problem) {
            self::assertStringContainsString('[a-z]+', $problem->getMessage());
            self::assertStringContainsString('[a-z]+', $problem->fix);
            self::assertSame('[a-z]+', $problem->context['pattern']);
            self::assertStringNotContainsString('Unknown modifier', $problem->getMessage());
        }
    }
}
