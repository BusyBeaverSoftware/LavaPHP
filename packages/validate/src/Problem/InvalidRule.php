<?php

declare(strict_types=1);

namespace Lava\Validate\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;
use Lava\Validate\Validation\DeclarationSite;
use Lava\Validate\Validation\Rule;

/**
 * A rule that cannot do its job, refused while the app is being wired.
 *
 * Every factory here is raised from a *constructor* or a builder method, never
 * from {@see \Lava\Validate\Validation\Rule::inspect()} — with one exception,
 * {@see predicateFailed()}, which is why that one takes its location as an
 * argument instead of walking the stack for it. The distinction matters: a rule
 * that cannot work is a mistake in the app's source, so it is found at boot —
 * once, with a file and a line — instead of on whichever request happens to
 * reach the field first. A `preg_match` warning on request 4,000 is the same
 * bug reported in the least useful possible way.
 *
 * **The source is a backtrace, unlike the db pack's `BadQuery`.** That
 * difference is deliberate. `BadQuery` is raised from a query the app *built*,
 * and the build often happens inside a library the app called — so a backtrace
 * there would point into vendor code and lie about whose mistake it is.
 * `InvalidRule` is raised while the app *declares* its fields, and a
 * declaration is app-authored by construction; the backtrace lands on the
 * `Field::…->regex(…)` line, which is exactly the line to edit. See
 * {@see DeclarationSite} for why the walk has to happen at declaration time.
 */
final class InvalidRule extends LavaProblem
{
    public function code(): string
    {
        return 'invalid_rule';
    }

    public static function unusablePattern(string $pattern, string $reason): self
    {
        return new self(
            "The pattern {$pattern} is not a usable PCRE pattern: {$reason}.",
            'Fix the pattern, or use a CustomRule for a check a regex cannot express. '
            . 'Note that the pattern is anchored for you, so \'/\\d+/\' already means "the whole value is digits".',
            ['pattern' => $pattern, 'reason' => $reason],
            self::caller(),
        );
    }

    /**
     * A pattern with no delimiters.
     *
     * Reported separately from {@see unusablePattern()} because the two need
     * different fixes, and because the anchoring step would otherwise rewrite
     * the caller's text before PCRE ever saw it — `'[a-z]+'` becomes
     * `'[^a-z]$['`, and PCRE then complains about a `$` the author never typed.
     * Diagnosing the pattern the author actually wrote is the whole point.
     */
    public static function undelimited(string $pattern): self
    {
        return new self(
            "The pattern {$pattern} has no delimiters, so it is not a PCRE pattern.",
            "Wrap it in delimiters, e.g. '/{$pattern}/'. Any character that is not a letter, a digit, a backslash or whitespace works — '#' and '~' are good choices because they rarely appear inside a pattern.",
            ['pattern' => $pattern],
            self::caller(),
        );
    }

    public static function emptyAllowedSet(): self
    {
        return new self(
            'in() was given an empty list of allowed values, so no value could ever pass.',
            'Pass at least one allowed value, e.g. ->in([\'draft\', \'published\']). '
            . 'If the set is built at runtime, build it before declaring the field — an empty set means the field is unsatisfiable, not optional.',
            ['rule' => 'in'],
            self::caller(),
        );
    }

    public static function negativeLength(string $rule, int $given): self
    {
        return new self(
            "{$rule}() was given a negative length ({$given}), which no string can have.",
            "Pass zero or more characters, e.g. ->{$rule}(0) to mean 'any length'.",
            ['rule' => $rule, 'bound' => $given],
            self::caller(),
        );
    }

    /**
     * `min()`/`max()` on a field that has no type rule.
     *
     * This is the one factory that exists because the pack REFUSES to guess.
     * The bound means "at least this many characters" for a string and "at
     * least this number" for a number, and with no type rule there is nothing
     * to decide from. Picking either would make the field's answer depend on
     * the data — the same rule passing or failing for reasons the author never
     * wrote down. The fix is the missing information, not a workaround.
     *
     * The field's name is not in the message because it is not knowable here:
     * the name is the array key in `Validator::of()`, and the chain is built
     * before the Validator sees it. The source location points at the line
     * where both the name and the chain are written, which is the line to edit.
     */
    public static function untypedBound(string $rule): self
    {
        return new self(
            "{$rule}() does not know whether its bound is a length or a value, because the field has no type rule.",
            "Start the chain with a type: Field::str()->{$rule}(3) bounds characters, Field::int()->{$rule}(3) bounds the value.",
            ['rule' => $rule],
            self::caller(),
        );
    }

    /** A length bound that is not a whole number of characters. */
    public static function fractionalLength(string $rule, int|float $bound): self
    {
        return new self(
            "{$rule}({$bound}) bounds characters, so the bound must be a whole number.",
            "Pass a whole number, e.g. Field::str()->{$rule}(3). For a bound on a numeric value, use Field::int() or Field::float().",
            ['rule' => $rule, 'bound' => $bound],
            self::caller(),
        );
    }

    /** A bound on a boolean field, which has neither a length nor a magnitude. */
    public static function boundOnBoolean(string $rule): self
    {
        return new self(
            "{$rule}() on a boolean field means nothing: a boolean has no length and is not a magnitude.",
            "Drop the bound, or declare the field as Field::int() if the value is really a number.",
            ['rule' => $rule],
            self::caller(),
        );
    }

    /**
     * A text refinement on a chain whose type is not text.
     *
     * `Field::int()->email()` cannot mean anything: the int rule decides the
     * field's shape, and no integer is an email address. Refused rather than
     * silently applied, because the alternative reading — that the author meant
     * to replace the type — would quietly drop the bound rules that were built
     * on the int type.
     */
    public static function incompatibleFormat(Rule $format, Rule $type): self
    {
        $formatName = $format->name();
        $typeName = $type->name();

        return new self(
            "{$formatName}() refines a text field, but this chain's type is '{$typeName}'.",
            "Start the chain with Field::str(), e.g. Field::str()->{$formatName}(). The type rule decides the field's shape, so a '{$typeName}' value cannot also be {$formatName}.",
            ['format' => $formatName, 'type' => $typeName],
            self::caller(),
        );
    }

    /**
     * A {@see \Lava\Validate\Validation\Rules\CustomRule} predicate threw.
     *
     * Reported as a framework fault, not as a validation failure: the app's
     * predicate is broken, and the caller did nothing wrong. Saying "your input
     * was invalid" here would be confidently wrong and perfectly actionable —
     * the caller would go edit a value that was never the problem. The original
     * throwable travels as `previous`, so the real stack trace is intact.
     *
     * `$declaredAt` is passed in rather than looked up, and that is the whole
     * reason this factory takes a location argument. This problem is raised
     * from `inspect()`, long after the chain was built, so a stack walk here
     * would land on the handler INVOCATION site and report the framework's own
     * `HandlerInvoker.php` as the app's mistake. The rule captured its
     * declaration site when it was constructed; that is the location the reader
     * needs, and it is the only moment it is still available.
     */
    public static function predicateFailed(
        string $rule,
        string $field,
        \Throwable $previous,
        ?SourceLocation $declaredAt = null,
    ): self {
        return new self(
            "The custom rule '{$rule}' on field '{$field}' threw " . $previous::class . ': ' . $previous->getMessage(),
            "Fix the predicate passed to ->custom() for '{$field}' — it must return a bool and must not throw. "
            . 'Anything it needs beyond the value (a database lookup, another field) has to be resolved before validation.',
            ['rule' => $rule, 'field' => $field, 'thrown' => $previous::class],
            $declaredAt,
            $previous,
        );
    }

    /**
     * The first stack frame outside this package — the app's declaration site.
     *
     * Only correct when the stack still contains the app's call site, i.e. while
     * a chain is being built. See {@see DeclarationSite} for the two moments and
     * why only one of them works.
     */
    private static function caller(): SourceLocation
    {
        return DeclarationSite::ofCaller();
    }
}
