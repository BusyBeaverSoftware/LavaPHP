<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Problem\LavaProblem;

/**
 * Turn problems into something a form can render.
 *
 * This class exists because of what the framework deliberately does not do:
 * there is no content negotiation, so a route that answers HTML must keep
 * answering HTML even when the request was bad. `HttpErrors::forReport()` does
 * negotiate — it is not the problem — but the HTML it negotiates *to* is
 * `DiagnosticsPage`, headed "LavaPHP found 1 problem", which is the wrong page
 * for a visitor who has just mistyped a field.
 *
 * So the HTML form routes take this path instead: validation failures become
 * per-field messages on a re-rendered form with a 422, and only `/api/*` returns
 * the envelope.
 */
final class FormErrors
{
    /** The key for a problem that belongs to no single field — a wrong password. */
    public const FORM = '_form';

    /**
     * One message per field, from a validation run.
     *
     * @param iterable<LavaProblem> $problems
     * @return array<string, string>
     */
    public static function collect(iterable $problems): array
    {
        $errors = [];

        foreach ($problems as $problem) {
            $field = $problem->context['field'] ?? null;
            $key = is_string($field) && $field !== '' ? $field : self::FORM;

            // First message per field wins: `Field::str()->required()->min(12)`
            // can only fail one rule at a time, but a future chain could fail
            // two, and the first is the one that explains the others.
            $errors[$key] ??= $problem->getMessage();
        }

        return $errors;
    }

    /**
     * A single problem, as a form-level message.
     *
     * Deliberately the message alone and not the `fix`: the fix is written for
     * whoever maintains this app ("compare the two in the handler"), and showing
     * it to somebody who mistyped their confirmation field would be an
     * instruction they cannot act on.
     *
     * @return array<string, string>
     */
    public static function single(LavaProblem $problem): array
    {
        return [self::FORM => $problem->getMessage()];
    }
}
