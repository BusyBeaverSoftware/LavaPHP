<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A request declared a JSON body that is not one.
 *
 * This is the caller's mistake, so it is a 400 and not a 500 — and it is a
 * *problem* rather than a `null` parsed body, which is the decision that
 * matters. Leaving the body unparsed would hand the handler `null`, the
 * validation layer would report every field as missing, and the caller would
 * go and add fields that were already in the request. A syntax error
 * presented as a missing field is the most expensive kind of wrong answer:
 * it is confidently actionable.
 *
 * The body itself never reaches `context`. Request bodies carry passwords and
 * tokens, and a problem report is written to logs, to `--json` output, and
 * into issue trackers — so the context carries what went wrong with the body
 * and never the body.
 */
final class MalformedBody extends LavaProblem
{
    public static function unparsable(string $contentType, string $jsonError): self
    {
        return new self(
            "The request body is not valid JSON: {$jsonError}.",
            'Send valid JSON, or send it as a form instead: Content-Type: application/x-www-form-urlencoded.',
            ['content_type' => $contentType, 'json_error' => $jsonError],
        );
    }

    public static function notAnObject(string $contentType, string $type): self
    {
        return new self(
            "The request body is valid JSON but is {$type}, and a request body must be a JSON object.",
            'Wrap the value in an object — {"value": …} — so the fields to validate have names.',
            ['content_type' => $contentType, 'decoded_as' => $type],
        );
    }

    public function code(): string
    {
        return 'malformed_body';
    }

    /** The request was well-formed HTTP and bad input: the caller's fault, not ours. */
    public function httpStatus(): int
    {
        return 400;
    }
}
