<?php

declare(strict_types=1);

namespace Lava\Validate\Validation;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Validation\Rules\BoolRule;
use Lava\Validate\Validation\Rules\CustomRule;
use Lava\Validate\Validation\Rules\EmailRule;
use Lava\Validate\Validation\Rules\FloatRule;
use Lava\Validate\Validation\Rules\InRule;
use Lava\Validate\Validation\Rules\IntRule;
use Lava\Validate\Validation\Rules\MaxRule;
use Lava\Validate\Validation\Rules\MinRule;
use Lava\Validate\Validation\Rules\RegexRule;
use Lava\Validate\Validation\Rules\RequiredRule;
use Lava\Validate\Validation\Rules\StringRule;
use Lava\Validate\Validation\Rules\UuidRule;

/**
 * One field's declaration, built as a chain.
 *
 * ```php
 * Validator::of([
 *     'email'    => Field::str()->required()->email()->max(254),
 *     'age'      => Field::int()->required()->min(18),
 *     'nickname' => Field::str()->max(20),
 *     'role'     => Field::any()->required()->in(['editor', 'admin']),
 * ]);
 * ```
 *
 * **The name is the array key, not an argument.** That is why a field cannot be
 * declared twice: PHP array keys make the duplicate unrepresentable, so there
 * is no check to write and no state to get wrong. It also means the Validator's
 * field list and the request body have the same shape, and a `Field` object
 * carries no identity — the same `Field::str()->email()` value can back two
 * differently-named fields.
 *
 * **Fields are OPTIONAL unless `->required()` says otherwise.** The chain reads
 * as a condition on a value that exists: `->max(20)` on an absent nickname must
 * not fire, or "nickname is optional but must be short" would be impossible to
 * express. Requiring is therefore the thing that has to be said out loud, which
 * is also the safer reading — a forgotten `->required()` on a create form
 * produces an empty column, while a forgotten `->optional()` on a patch form
 * rejects every request that omits the field.
 *
 * **The chain starts with a type, and that is what makes `->min()` decidable.**
 * A bound means "at least this many characters" on a string and "at least this
 * value" on a number; the value cannot say which, so the type rule — added
 * first, exactly once — decides. `Field::str()->min(2)` and `Field::int()->min(2)`
 * build different rules from the same call. A chain with no type has nothing to
 * decide from, and `->min()` there is refused rather than guessed.
 *
 * **The type constructors are static, so they must be the first call.** PHP
 * allows a static method to be called through an instance, and
 * `Field::str()->required()->email()` would then return a brand-new field and
 * silently discard the `required()` that came before it — a required field that
 * accepts a missing value. `->email()` and `->uuid()` are therefore *instance*
 * methods that refine the type in place, and the remaining constructors
 * (`str`, `int`, `float`, `bool`, `any`) are the only way to start a chain.
 * A text refinement on a non-text chain is refused rather than applied.
 *
 * **Every method returns a new Field.** Chains are values, so a field built
 * once and used under two names cannot be mutated by one use and surprise the
 * other.
 */
final class Field
{
    /** @var list<Rule> */
    private array $rules = [];

    /** The type rule, if the chain started with one. At most one, by construction. */
    private ?Rule $type = null;

    private function __construct(?Rule $type)
    {
        $this->type = $type;
        if ($type !== null) {
            $this->rules[] = $type;
        }
    }

    /** The value must be a string. */
    public static function str(): self
    {
        return new self(new StringRule());
    }

    /** The value must be a whole number. */
    public static function int(): self
    {
        return new self(new IntRule());
    }

    /** The value must be a real number. */
    public static function float(): self
    {
        return new self(new FloatRule());
    }

    /** The value must be a boolean. */
    public static function bool(): self
    {
        return new self(new BoolRule());
    }

    /**
     * No type rule — for a field whose shape is settled by other rules.
     *
     * `Field::any()->required()->in(['editor', 'admin'])` is the honest way to
     * write a field that accepts either a JSON number or a form string: `in()`
     * compares by string form, so it does not care which arrived, and adding a
     * type rule would only invent a distinction the field does not have.
     */
    public static function any(): self
    {
        return new self(null);
    }

    /**
     * The value must look like an email address — a refinement of a text field.
     *
     * `Field::str()->required()->email()` keeps the `required()` that came
     * before it; the email rule REPLACES the text rule rather than being added
     * beside it, because "must be a string" is already implied and a second rule
     * that can never fire is a lie in the problem context, which names the rule
     * that actually stopped the value.
     *
     * @throws InvalidRule when the chain's type is not text.
     */
    public function email(): self
    {
        return $this->refine(new EmailRule());
    }

    /** The value must be a canonical UUID — a refinement of a text field. */
    public function uuid(): self
    {
        return $this->refine(new UuidRule());
    }

    /** The field must be present and non-empty. */
    public function required(): self
    {
        // First in the pipeline: the rule set reports "required" before any
        // other rule gets an opinion about a value that is not there.
        return $this->with(new RequiredRule(), first: true);
    }

    /**
     * A lower bound — characters for a string-ish field, value for a numeric one.
     *
     * @throws InvalidRule when the chain has no type rule to decide from.
     */
    public function min(int|float $bound): self
    {
        return $this->bound('min', $bound);
    }

    /**
     * An upper bound — characters for a string-ish field, value for a numeric one.
     *
     * @throws InvalidRule when the chain has no type rule to decide from.
     */
    public function max(int|float $bound): self
    {
        return $this->bound('max', $bound);
    }

    /**
     * The value must match a PCRE pattern. The pattern is anchored for you.
     *
     * @throws InvalidRule when the pattern is not usable.
     */
    public function regex(string $pattern): self
    {
        return $this->with(new RegexRule($pattern));
    }

    /**
     * The value must be one of a fixed set, compared by string form.
     *
     * @param list<string|int|float> $allowed
     * @throws InvalidRule when the set is empty.
     */
    public function in(array $allowed): self
    {
        return $this->with(new InRule($allowed));
    }

    /**
     * The value must satisfy a predicate the app supplies.
     *
     * The message and fix are required and are written by the same person as
     * the predicate — see {@see CustomRule} for why there is no default.
     *
     * @param \Closure(mixed): bool $check
     * @throws InvalidRule at validation time, if the predicate throws.
     */
    public function custom(
        string $name,
        \Closure $check,
        string $message,
        string $fix,
        mixed $expects = null,
    ): self {
        return $this->with(new CustomRule($name, $check, $message, $fix, $expects));
    }

    public function rules(): RuleSet
    {
        return new RuleSet($this->rules);
    }

    /** The type rule, for {@see Validated}'s accessors. Null for a `Field::any()` chain. */
    public function typeRule(): ?Rule
    {
        return $this->type;
    }

    private function with(Rule $rule, bool $first = false): self
    {
        $next = clone $this;
        $next->rules = $first ? [$rule, ...$this->rules] : [...$this->rules, $rule];

        return $next;
    }

    /**
     * Install or replace the type rule, keeping every other rule where it was.
     *
     * The type is what the rest of the chain reasons from — `->min(2)` asks it
     * whether the bound counts characters or magnitude — so refining it must not
     * disturb the rules that already made that decision. Replacing in place, by
     * object identity, is what keeps `Field::str()->min(2)->email()->max(254)`
     * a length bound on both ends.
     *
     * With no type yet (`Field::any()`), the new type goes to the front, since
     * every rule after it may be assuming it. {@see required()} is the one rule
     * that belongs ahead of the type — it decides whether there is a value to
     * inspect at all, before any rule can have an opinion about it.
     */
    private function refine(Rule $type): self
    {
        if ($this->type !== null
            && !$this->type instanceof StringRule
            && !$this->type instanceof EmailRule
            && !$this->type instanceof UuidRule) {
            throw InvalidRule::incompatibleFormat($type, $this->type);
        }

        $next = clone $this;
        $next->type = $type;

        if ($this->type !== null) {
            $next->rules = array_map(
                fn (Rule $rule): Rule => $rule === $this->type ? $type : $rule,
                $this->rules,
            );

            return $next;
        }

        $at = $this->rules !== [] && $this->rules[0] instanceof RequiredRule ? 1 : 0;
        $rules = $this->rules;
        array_splice($rules, $at, 0, [$type]);
        $next->rules = $rules;

        return $next;
    }

    /**
     * The single place the length-versus-value question is answered.
     *
     * A string-ish field gets a length bound; a numeric field gets a value
     * bound; a boolean field gets neither and is refused, because `true` has no
     * length and `false` is not a magnitude. A chain with no type rule has
     * nothing to decide from, and the refusal names the missing type rather
     * than picking a meaning for the author.
     */
    private function bound(string $rule, int|float $bound): self
    {
        if ($this->type instanceof StringRule
            || $this->type instanceof EmailRule
            || $this->type instanceof UuidRule) {
            if (!is_int($bound)) {
                throw InvalidRule::fractionalLength($rule, $bound);
            }
            return $this->with(
                $rule === 'min' ? MinRule::length($bound) : MaxRule::length($bound),
            );
        }

        if ($this->type instanceof IntRule || $this->type instanceof FloatRule) {
            return $this->with(
                $rule === 'min' ? MinRule::numeric($bound) : MaxRule::numeric($bound),
            );
        }

        if ($this->type instanceof BoolRule) {
            throw InvalidRule::boundOnBoolean($rule);
        }

        throw InvalidRule::untypedBound($rule);
    }
}
