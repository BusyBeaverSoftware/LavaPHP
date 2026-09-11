<?php

declare(strict_types=1);

namespace Lava\Validate\Validation\Rules;

use Lava\Validate\Problem\InvalidRule;
use Lava\Validate\Validation\Rule;
use Lava\Validate\Validation\RuleViolation;

/**
 * The field must match a PCRE pattern.
 *
 * The pattern is validated when the rule is BUILT, not when a value arrives.
 * A malformed pattern is a mistake in the app's source, and it must be found
 * where it is written — at boot, with a file and a line — rather than as a
 * `preg_match` warning on the first request that happens to reach the field.
 * That is why the constructor can throw {@see InvalidRule} while
 * {@see inspect()} cannot.
 *
 * The pattern is anchored for you: `'/\d+/'` means "the whole value is
 * digits", not "contains digits". Unanchored matching is the single most
 * common validation bug — `'/\d+/'` accepts `'abc5'` — and a caller who
 * genuinely wants a substring match writes the unanchored pattern with `.*`
 * around it, which is a decision they have to make on purpose.
 *
 * **The pattern is PARSED, not sliced.** A pattern is a delimiter, a body and
 * optional modifiers — `'/abc/i'` — so the body does not simply run from index
 * 1 to the second-to-last character. Slicing that way turns `'/abc/i'` into
 * `/^abc/$/` and refuses a pattern that was perfectly good, which is why
 * {@see parse()} finds the closing delimiter from the end and keeps whatever
 * modifiers follow it. It also means the diagnosis stays PCRE's own: a pattern
 * with no delimiters at all is reported as undelimited rather than being
 * rewritten into something PCRE complains about for a reason the author cannot
 * see.
 *
 * @phpstan-type ParsedPattern array{delimiter: string, closer: string, body: string, modifiers: string}
 */
final class RegexRule extends Rule
{
    /** PCRE's four bracket delimiters, each with the closer it must be paired with. */
    private const PAIRED = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /** The pattern as it will be applied: delimiters, anchored body, modifiers. */
    private readonly string $pattern;

    public function __construct(string $pattern)
    {
        $parsed = self::parse($pattern);

        if ($parsed === null) {
            throw InvalidRule::undelimited($pattern);
        }

        $this->pattern = self::anchor($parsed);

        // The warning is CAPTURED, not suppressed, and that is not a detail:
        // `preg_last_error_msg()` reports every compile failure as "Internal
        // error", which tells the author nothing at all, while the warning PHP
        // emits names the actual defect ("missing closing parenthesis at offset
        // 3"). A rule that refuses a pattern owes the author that sentence.
        //
        // The handler is scoped to this one call and popped in `finally`, so a
        // caller's own handler — or a test harness's — is never left displaced.
        $complaint = null;
        set_error_handler(static function (int $severity, string $message) use (&$complaint): bool {
            $complaint = $message;
            return true;
        });

        try {
            $usable = preg_match($this->pattern, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (!$usable) {
            throw InvalidRule::unusablePattern($pattern, self::reason($complaint));
        }
    }

    public function name(): string
    {
        return 'regex';
    }

    /** The anchored pattern, so a failure can show what was expected. */
    public function expects(): mixed
    {
        return $this->pattern;
    }

    public function inspect(string $field, mixed $value): ?RuleViolation
    {
        if (is_string($value) && preg_match($this->pattern, $value) === 1) {
            return null;
        }

        return new RuleViolation(
            "Field '{$field}' must match the pattern {$this->pattern}.",
            "Send '{$field}' as a string matching {$this->pattern} — the pattern is anchored, so the whole value must match.",
        );
    }

    /**
     * Splits a delimited pattern into its parts, or returns null when it is not
     * a delimited pattern at all.
     *
     * The closing delimiter is found by scanning BACKWARD for the last one that
     * leaves only modifiers after it. Scanning forward would stop at the first
     * closer, which is wrong whenever the body contains the delimiter
     * (`'/a\/b/'`, `'/[a-z]/'`) — and taking the second-to-last character
     * instead, as a slice would, breaks every pattern with a modifier.
     *
     * A leading character that could not be a delimiter is not treated as one:
     * PCRE forbids letters, digits, backslashes and whitespace as delimiters, so
     * `'[a-z]+'` is reported as undelimited rather than being read as a
     * `[`-delimited pattern with a `+` modifier — a reading PCRE itself would
     * accept, but never the one the author meant.
     *
     * @return ParsedPattern|null
     */
    private static function parse(string $pattern): ?array
    {
        if ($pattern === '') {
            return null;
        }

        $delimiter = $pattern[0];

        if (ctype_alnum($delimiter) || $delimiter === '\\' || ctype_space($delimiter)) {
            return null;
        }

        $closer = self::PAIRED[$delimiter] ?? $delimiter;

        for ($at = strlen($pattern) - 1; $at >= 1; $at--) {
            if ($pattern[$at] !== $closer) {
                continue;
            }

            $modifiers = substr($pattern, $at + 1);
            if (preg_match('/^[a-zA-Z]*$/', $modifiers) !== 1) {
                continue;
            }

            return [
                'delimiter' => $delimiter,
                'closer' => $closer,
                'body' => substr($pattern, 1, $at - 1),
                'modifiers' => $modifiers,
            ];
        }

        return null;
    }

    /**
     * The pattern with `^…$` around its body, unless the caller anchored it.
     *
     * Idempotent on purpose: `'/^\d+$/'` and `'/\d+/'` produce the same
     * pattern, so a caller who anchored by hand does not get `^` twice — which
     * would be a valid pattern that matches nothing. Modifiers are kept outside
     * the anchoring, where PCRE expects them.
     *
     * @param ParsedPattern $parsed
     */
    private static function anchor(array $parsed): string
    {
        $body = $parsed['body'];

        if (str_starts_with($body, '^') || str_ends_with($body, '$')) {
            return $parsed['delimiter'] . $body . $parsed['closer'] . $parsed['modifiers'];
        }

        return $parsed['delimiter'] . '^' . $body . '$' . $parsed['closer'] . $parsed['modifiers'];
    }

    /**
     * The warning text without PHP's `preg_match(): ` prefix.
     *
     * The prefix is the function name, and by the time the author reads this it
     * is in a problem whose message already says a pattern was refused — so it
     * is noise in front of the one sentence that matters. The fallback exists
     * for the case where no warning arrived at all (a displaced error handler,
     * say): saying "no reason was reported" is honest, where an empty string
     * would look like a formatting bug.
     */
    private static function reason(?string $complaint): string
    {
        if ($complaint === null) {
            return 'PCRE refused it and reported no reason';
        }

        $colon = strpos($complaint, ': ');

        return $colon === false ? $complaint : substr($complaint, $colon + 2);
    }
}
