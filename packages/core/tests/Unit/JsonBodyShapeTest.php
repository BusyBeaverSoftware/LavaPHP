<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Http\RequestBody;
use Lava\Core\Problem\MalformedBody;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Which JSON bodies are objects (Lava Notes round 4, R4-B1).
 *
 * `docs/problem-codes.md` and `docs/packs/lava-validate.md` both say a body that
 * is valid JSON and not an object is refused. The check was `is_array()`, which
 * a JSON list passes, so `[1,2,3]` reached handlers that then had to guard for
 * it themselves — twelve lines per endpoint in the build that reported this.
 */
final class JsonBodyShapeTest extends TestCase
{
    private static function json(string $body): ServerRequest
    {
        $request = new ServerRequest('POST', '/posts', ['Content-Type' => 'application/json']);
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        return $request;
    }

    public function testAnObjectIsParsedIntoTheParsedBody(): void
    {
        $parsed = RequestBody::parsed(self::json('{"title":"Hello","tags":["a","b"]}'))->getParsedBody();

        self::assertSame(['title' => 'Hello', 'tags' => ['a', 'b']], $parsed, 'A nested list inside an object is data, not the shape being refused.');
    }

    public function testAListIsRefusedAndToldWhatToSendInstead(): void
    {
        try {
            RequestBody::parsed(self::json('[1,2,3]'));
            self::fail('A JSON list is not an object and must be refused.');
        } catch (MalformedBody $problem) {
            self::assertSame('malformed_body', $problem->code());
            self::assertSame(400, $problem->httpStatus());
            self::assertStringContainsString('is a list', $problem->getMessage());
            // "Wrap the value" is the wrong instruction here: the caller already
            // sent a collection and needs to give it a name.
            self::assertStringContainsString('{"items": […]}', $problem->fix);
            self::assertSame('a list', $problem->context['decoded_as']);
        }
    }

    public function testEveryOtherNonObjectIsStillRefusedWithItsOwnType(): void
    {
        foreach (['"hello"' => 'string', '42' => 'int', 'true' => 'bool', '1.5' => 'float'] as $body => $type) {
            try {
                RequestBody::parsed(self::json((string) $body));
                self::fail("A JSON {$type} is not an object and must be refused.");
            } catch (MalformedBody $problem) {
                self::assertSame($type, $problem->context['decoded_as'], (string) $body);
                self::assertStringContainsString('{"value": …}', $problem->fix, (string) $body);
            }
        }
    }

    public function testAnEmptyObjectIsAcceptedBecauseAnEmptyListIsIndistinguishable(): void
    {
        // `{}` and `[]` both decode to `[]`, so refusing the list would refuse
        // the object with it. A request that sends no fields is answered better
        // by validation naming them than by a parse error about the shape.
        self::assertSame([], RequestBody::parsed(self::json('{}'))->getParsedBody());
        self::assertSame([], RequestBody::parsed(self::json('[]'))->getParsedBody());
    }

    public function testABodyThatIsNotJsonIsUntouched(): void
    {
        // The rule is about what the request SAID it was sending: a list under a
        // content type core does not claim to parse is the handler's business.
        $request = new ServerRequest('POST', '/posts', ['Content-Type' => 'text/plain']);
        $request->getBody()->write('[1,2,3]');
        $request->getBody()->rewind();

        self::assertNull(RequestBody::parsed($request)->getParsedBody());
    }
}
