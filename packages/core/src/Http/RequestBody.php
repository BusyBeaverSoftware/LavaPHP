<?php

declare(strict_types=1);

namespace Lava\Core\Http;

use Lava\Core\Problem\MalformedBody;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The request's body, parsed — for both content types a client actually sends.
 *
 * PSR-7 leaves this to the application, and the SAPI adapter only fills
 * `parsedBody` for form posts, because that is all PHP's own `$_POST` covers.
 * A JSON body therefore arrives with `getParsedBody() === null` and the bytes
 * sitting unread in the stream, which in an agent-first framework is the
 * framework failing at its own pillar: the client most likely to POST here
 * sends JSON, and the handler would have to hand-decode it every time.
 *
 * So there is one rule, and it is a rule about what the REQUEST said:
 *
 *  - a JSON content type and a non-empty body → the body is JSON, and either
 *    it parses into `parsedBody` or the request is refused with
 *    {@see MalformedBody} (400). A body that declares itself JSON and is not
 *    is the caller's mistake, and reporting it as one is the whole point —
 *    silently leaving `parsedBody` null would present a syntax error as a
 *    missing field, and the caller would go and add a field that was there.
 *  - anything else → untouched. Form posts already arrived parsed; a body
 *    that is not JSON belongs to the handler, which is the only code that
 *    knows what it is (a webhook payload, an uploaded blob, XML).
 *
 * An already-parsed body is never re-parsed, so a caller that built a request
 * by hand — every test that sends a body — keeps exactly what it set.
 */
final class RequestBody
{
    /** The content types that mean "this is JSON", ignoring parameters like charset. */
    private const JSON_TYPES = ['application/json', 'text/json'];

    /**
     * @throws MalformedBody when the request declares a JSON body that does not parse.
     */
    public static function parsed(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getParsedBody() !== null) {
            return $request;
        }

        if (!self::declaresJson($request)) {
            return $request;
        }

        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            // An empty body is not malformed JSON — it is no body at all. A
            // client that POSTs nothing gets a validation report naming every
            // required field, which is a better answer than a parse error
            // about an empty string.
            return $request;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw MalformedBody::unparsable(self::contentType($request), $error->getMessage());
        }

        if (!is_array($decoded)) {
            // Valid JSON, wrong shape: `"hello"`, `42`, `null`. A body that is
            // not an object has no fields to validate, and passing it on would
            // make every field look missing.
            throw MalformedBody::notAnObject(self::contentType($request), get_debug_type($decoded));
        }

        return $request->withParsedBody($decoded);
    }

    private static function declaresJson(ServerRequestInterface $request): bool
    {
        return in_array(self::contentType($request), self::JSON_TYPES, true);
    }

    /** The media type alone: `application/json; charset=utf-8` → `application/json`. */
    private static function contentType(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Content-Type');
        return strtolower(trim(explode(';', $header, 2)[0]));
    }
}
