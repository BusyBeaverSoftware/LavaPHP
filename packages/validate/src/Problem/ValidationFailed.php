<?php

declare(strict_types=1);

namespace Lava\Validate\Problem;

use Lava\Core\Config\Secrets;
use Lava\Core\Problem\LavaProblem;
use Lava\Validate\Validation\RuleFailure;

/**
 * The input was readable, and the app refused it.
 *
 * This is the one problem in the framework that is the *caller's* fault, and it
 * is the only reason `httpStatus()` exists at all: a 422 has to be able to come
 * from a pack, because the knowledge that makes the fix text worth reading —
 * which field, which rule, what it wanted — lives in this pack and nowhere
 * else. Core must not enumerate the codes of packs it has never heard of.
 *
 * **One problem per failed field, never one problem with a list inside it.**
 * A request with three bad fields produces three problems in the report, each
 * with its own `context.field`. That is what makes the response work for both
 * readers: an agent iterates `problems[]` and fixes them all in one pass, and a
 * human sees three sentences about three fields rather than one sentence about
 * a payload. The status comes from the first problem and is 422 either way.
 *
 * The context is the whole diagnosis — `field`, `rule`, `expects`, `value`,
 * `sent` — and `value` is the only field that can be sensitive. It is replaced
 * with the framework's redaction constant when the FIELD NAME looks like a
 * secret (`password`, `api_key`, …), because a 422 response body is logged,
 * echoed into terminals, and pasted into bug reports. The value is never in
 * `message` in the first place: the rule author writes prose about the shape of
 * what was wanted, not a quotation of what arrived, so redaction here is a
 * second line of defence rather than the only one.
 *
 * `sent` distinguishes "you did not include the key" from "you sent null" —
 * both arrive here as a null value, and they have different fixes.
 */
final class ValidationFailed extends LavaProblem
{
    public static function of(string $field, RuleFailure $failure, mixed $value, bool $present): self
    {
        return new self(
            $failure->violation->message,
            $failure->violation->fix,
            [
                'field' => $field,
                'rule' => $failure->rule->name(),
                'expects' => $failure->rule->expects(),
                'value' => self::reportable($field, $value),
                'sent' => $present,
            ],
        );
    }

    public function code(): string
    {
        return 'validation_failed';
    }

    /** 422 Unprocessable Content: the request was understood and the content was not acceptable. */
    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * The value as it can safely appear in a report.
     *
     * A secret-shaped field never shows its value, whatever it held. Everything
     * else is shown as it arrived: scalars as themselves, arrays as their JSON
     * text (so the context stays JSON-safe), and anything that is neither — an
     * object, a resource, an array that cannot be encoded — as its type name.
     * Reporting a type is not a loss of information here: a rule failing on an
     * object means the caller sent the wrong shape, and the type is the whole
     * diagnosis.
     *
     * Public because it is this pack's single rule for how a field's value
     * appears in a problem, shared with {@see UnreadableField}: a redaction
     * policy that lived in two places would eventually hide a password in one
     * report and print it in the other.
     */
    public static function reportable(string $field, mixed $value): mixed
    {
        if (Secrets::looksSecret($field)) {
            return Secrets::redacted();
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $encoded === false ? get_debug_type($value) : $encoded;
        }

        return get_debug_type($value);
    }
}
