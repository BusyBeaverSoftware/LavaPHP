<?php

declare(strict_types=1);

namespace Lava\Core\Http;

use Lava\Core\Problem\MalformedBody;
use Lava\Core\Problem\RequestTooLarge;
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
 *
 * One request is refused before any of that: a form post PHP discarded for
 * exceeding `post_max_size`, which arrives as a form with no fields and no
 * files. It is {@see RequestTooLarge} (413), because every layer downstream
 * would otherwise blame a field the visitor did fill in.
 */
final class RequestBody
{
    /** The content types that mean "this is JSON", ignoring parameters like charset. */
    private const JSON_TYPES = ['application/json', 'text/json'];

    /** The content types whose fields PHP parses itself, and therefore can discard. */
    private const FORM_TYPES = ['application/x-www-form-urlencoded', 'multipart/form-data'];

    /**
     * @param int|null $formLimit the byte limit for a form post, for a test that
     *        cannot set `post_max_size` (it is PHP_INI_PERDIR); null reads the ini value
     *
     * @throws MalformedBody when the request declares a JSON body that does not parse.
     * @throws RequestTooLarge when PHP discarded a form post larger than post_max_size.
     */
    public static function parsed(ServerRequestInterface $request, ?int $formLimit = null): ServerRequestInterface
    {
        $discarded = self::discardedForm($request, $formLimit);
        if ($discarded !== null) {
            throw $discarded;
        }

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

    /**
     * The problem for a form post PHP threw away, or null.
     *
     * All of it has to hold, because each part alone is ordinary: a form
     * content type (PHP parses no other), no fields and no files (what a
     * discarded form looks like), and a `Content-Length` over the limit (what
     * makes it PHP's doing rather than an empty form someone submitted). The
     * body's raw bytes are usually still in the stream — PHP drops the parse,
     * not the request — but they are not a form any more, so a handler cannot
     * recover the fields from them.
     */
    private static function discardedForm(ServerRequestInterface $request, ?int $limit): ?RequestTooLarge
    {
        $type = self::contentType($request);
        if (!in_array($type, self::FORM_TYPES, true)) {
            return null;
        }
        $parsed = $request->getParsedBody();
        if (($parsed !== null && $parsed !== []) || $request->getUploadedFiles() !== []) {
            return null;
        }

        $limit ??= ini_parse_quantity((string) ini_get('post_max_size'));
        $length = (int) $request->getHeaderLine('Content-Length');
        if ($limit <= 0 || $length <= $limit) {
            // A limit of 0 is "no limit" in PHP's own reading, so there is
            // nothing for it to have discarded.
            return null;
        }

        return RequestTooLarge::form($type, $length, $limit);
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
