<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Http;

use Lava\Core\Boot\App;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Lava\Core\Testing\TestResponse;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The pack over real HTTP, through the fixture app.
 *
 * A unit test can assert that a rule refuses a value. What it cannot show is
 * that the refusal becomes a 422 with a machine-readable body, that a value read
 * back by the handler is the coerced one, or that an app defect becomes a 500
 * instead of a 422 — and those are the differences the pack exists to produce.
 *
 * The fixture declares its fields inline, so the responses here are the whole
 * path: parse → validate → `HttpErrors::forReport()` → JSON body. The password
 * is never echoed by the handler and never appears in a 422, which is worth
 * asserting from the outside rather than trusting the unit test.
 */
final class ValidationOverHttpTest extends TestCase
{
    private static function appDir(): string
    {
        return dirname(__DIR__) . '/fixtures/apps/validate-app';
    }

    private static function client(): TestClient
    {
        $app = TestApp::boot(self::appDir());
        self::assertInstanceOf(App::class, $app, 'the fixture app should boot');

        return new TestClient($app);
    }

    /** @return array<string, mixed> */
    private static function registration(): array
    {
        return [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'age' => '34',
            'role' => 'editor',
            'terms' => 'on',
        ];
    }

    public function testAValidRegistrationReadsBackAsTheTypesTheHandlerAskedFor(): void
    {
        // `age` arrives as the string '34' and `terms` as 'on' — form spellings,
        // even though the body here is JSON. The handler reads them as an int
        // and a bool because the rules already decided those were the types.
        $response = self::client()->json('POST', '/users', self::registration());

        self::assertSame(200, $response->status());
        self::assertSame([
            'user' => [
                'email' => 'ada@example.com',
                'age' => 34,
                'nickname' => null,
                'role' => 'editor',
                'terms' => true,
            ],
            'password_length' => 21,
        ], $response->json());
    }

    public function testTheSameFieldsCoerceIdenticallyFromAFormBody(): void
    {
        // The content type must not change the answer: a browser posts a form
        // and a client posts JSON, and both are the same request.
        $response = self::client()->form('POST', '/users', self::registration());

        self::assertSame(200, $response->status());
        self::assertSame(34, $response->json()['user']['age']);
        self::assertTrue($response->json()['user']['terms']);
    }

    public function testTheHandlerNeverEchoesThePassword(): void
    {
        $response = self::client()->json('POST', '/users', self::registration());

        self::assertStringNotContainsString('correct-horse-battery', $response->body());
        self::assertSame(21, $response->json()['password_length']);
    }

    public function testABadRequestIsA422ListingEveryBadFieldAtOnce(): void
    {
        // One round trip to fix four fields. The alternative — first failure
        // wins — turns a form with four mistakes into four requests.
        $response = self::client()->json('POST', '/users', [
            'email' => 'not-an-email',
            'password' => 'short',
            'age' => 12,
            'role' => 'root',
            'terms' => 'maybe',
        ]);

        self::assertSame(422, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));

        $problems = $response->json()['problems'];
        self::assertCount(5, $problems);
        self::assertSame(array_fill(0, 5, 'validation_failed'), array_column($problems, 'code'));
        self::assertSame(
            ['email', 'password', 'age', 'role', 'terms'],
            array_column(array_column($problems, 'context'), 'field'),
        );
    }

    public function testEveryProblemCarriesTheRuleAndWhatItWanted(): void
    {
        // The machine-readable half of the diagnosis: an agent reads `rule` and
        // `expects` and can fix the request without any prose.
        $response = self::client()->json('POST', '/users', [
            'email' => 'ada@example.com',
            'password' => 'short',
            'age' => 12,
            'role' => 'editor',
            'terms' => 'on',
        ]);

        $problem = $response->json()['problems'][0];
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame('password', $problem['context']['field']);
        self::assertSame('min', $problem['context']['rule']);
        self::assertSame(['bound' => 12, 'of' => 'characters'], $problem['context']['expects']);
        self::assertTrue($problem['context']['sent']);
        self::assertStringContainsString('at least 12 characters', $problem['problem']);
        self::assertNotSame('', $problem['fix']);
    }

    public function testAPasswordNeverAppearsInAFailureBody(): void
    {
        // The value is redacted on the way into the context, so a 422 body —
        // which is logged, echoed into terminals and pasted into tickets —
        // cannot carry a credential.
        $response = self::client()->json('POST', '/users', [
            'email' => 'ada@example.com',
            'password' => 'hunter2',
            'age' => 34,
            'role' => 'editor',
            'terms' => 'on',
        ]);

        self::assertSame(422, $response->status());
        self::assertSame('<redacted>', $response->json()['problems'][0]['context']['value']);
        self::assertStringNotContainsString('hunter2', $response->body());
    }

    public function testARequiredEmailIsActuallyRequired(): void
    {
        // The regression this fixture pins: `email()` used to be a static
        // constructor, so `Field::str()->required()->email()` silently dropped
        // the `required()` and an omitted email passed validation.
        $response = self::client()->json('POST', '/users', [
            'password' => 'correct-horse-battery',
            'age' => 34,
            'role' => 'editor',
            'terms' => 'on',
        ]);

        self::assertSame(422, $response->status());
        self::assertSame('email', $response->json()['problems'][0]['context']['field']);
        self::assertSame('required', $response->json()['problems'][0]['context']['rule']);
        self::assertFalse($response->json()['problems'][0]['context']['sent']);
    }

    public function testAnOptionalFieldMayBeOmittedAndIsThenAbsent(): void
    {
        $response = self::client()->json('POST', '/users', self::registration());

        self::assertSame(200, $response->status());
        self::assertNull($response->json()['user']['nickname']);
    }

    public function testAnOptionalFieldMayBeSentAndIsThenKept(): void
    {
        $response = self::client()->json('POST', '/users', self::registration() + ['nickname' => 'ada']);

        self::assertSame('ada', $response->json()['user']['nickname']);
    }

    public function testUndeclaredKeysCannotReachTheHandler(): void
    {
        // The field map is the allow-list, so a registration form cannot pass
        // an `is_admin` through to whatever the handler does with the values.
        $response = self::client()->json('POST', '/users', self::registration() + [
            'is_admin' => true,
            'id' => 1,
        ]);

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('is_admin', $response->body());
        self::assertArrayNotHasKey('id', $response->json()['user']);
    }

    public function testACrossFieldRuleIsReportedWithItsOwnFix(): void
    {
        // The documented way to write a cross-field check: the payload is read
        // in the handler and captured by the predicate, so the dependency is
        // visible in the code that has it. The rule's `{field}` placeholder is
        // substituted, so a renamed field cannot leave a stale name behind.
        $response = self::client()->json('POST', '/events', [
            'starts_at' => '2026-05-02',
            'ends_at' => '2026-05-01',
        ]);

        self::assertSame(422, $response->status());
        $problem = $response->json()['problems'][0];
        self::assertSame('after_starts_at', $problem['context']['rule']);
        self::assertSame(['not_before' => 'starts_at'], $problem['context']['expects']);
        self::assertSame("'ends_at' must not be earlier than 'starts_at'.", $problem['problem']);
        self::assertStringContainsString("'ends_at'", $problem['fix']);
    }

    public function testACrossFieldRulePassesWhenTheOrderIsRight(): void
    {
        $response = self::client()->json('POST', '/events', [
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-02',
        ]);

        self::assertSame(200, $response->status());
        self::assertSame(
            ['starts_at' => '2026-05-01', 'ends_at' => '2026-05-02'],
            $response->json()['event'],
        );
    }

    public function testACrossFieldRuleDoesNotBlameItsFieldWhenTheOtherIsMissing(): void
    {
        // A rule has two outcomes, pass or refuse, and "the field I depend on is
        // not there" is not a reason to refuse `ends_at`. Returning false here
        // would report `after_starts_at` about a comparison that never happened,
        // on a request whose only mistake is the missing `starts_at`.
        $response = self::client()->json('POST', '/events', ['ends_at' => '2026-05-02']);

        self::assertSame(422, $response->status());
        self::assertCount(1, $response->json()['problems']);
        self::assertSame('starts_at', $response->json()['problems'][0]['context']['field']);
        self::assertSame('required', $response->json()['problems'][0]['context']['rule']);
    }

    public function testABadDateOnBothFieldsIsReportedOnBothFields(): void
    {
        // The other half of the same rule: when `starts_at` IS readable, the
        // cross-field check does its job and the caller hears about both.
        $response = self::client()->json('POST', '/events', [
            'starts_at' => 'not-a-date',
            'ends_at' => 'also-not-a-date',
        ]);

        self::assertSame(422, $response->status());
        self::assertSame(
            ['starts_at', 'ends_at'],
            array_column(array_column($response->json()['problems'], 'context'), 'field'),
        );
    }

    public function testABrokenPredicateIsA500NamingTheAppsOwnFile(): void
    {
        // The distinction the pack turns on, observed over HTTP: the caller
        // sent a perfectly good slug, so blaming the request would send them to
        // edit a value that was never the problem. The source points at the
        // fixture's own controller, which is the line to fix.
        $response = self::client()->post('/broken');

        self::assertSame(500, $response->status());
        $problem = $response->json()['problems'][0];
        self::assertSame('invalid_rule', $problem['code']);
        self::assertStringContainsString('the predicate itself is broken', $problem['problem']);
        self::assertSame('RuntimeException', $problem['context']['thrown']);
        self::assertStringContainsString('BrokenController.php', $problem['source']['file']);
        self::assertGreaterThan(0, $problem['source']['line']);
    }

    public function testAJsonBodyThatIsNotAnObjectIsRefusedBeforeRouting(): void
    {
        // A body that is valid JSON but not an object has no fields to validate,
        // and passing it on would present a shape error as every field being
        // missing. This request is built by hand because `TestClient` always
        // sets a parsed body — which is exactly the case `RequestBody` skips.
        $app = TestApp::boot(self::appDir());
        self::assertInstanceOf(App::class, $app);

        $response = new TestResponse($app->handle(self::rawJson('POST', '/users', '"just a string"')));

        self::assertSame(400, $response->status());
        self::assertSame('malformed_body', $response->json()['problems'][0]['code']);
        self::assertStringContainsString('string', $response->json()['problems'][0]['problem']);
    }

    public function testAJsonBodyThatDoesNotParseIsRefusedWithTheParserMessage(): void
    {
        $app = TestApp::boot(self::appDir());
        self::assertInstanceOf(App::class, $app);

        $response = new TestResponse($app->handle(self::rawJson('POST', '/users', '{"email":')));

        self::assertSame(400, $response->status());
        $problem = $response->json()['problems'][0];
        self::assertSame('malformed_body', $problem['code']);
        self::assertSame('application/json', $problem['context']['content_type']);
        self::assertSame('Syntax error', $problem['context']['json_error']);
        self::assertStringContainsString('not valid JSON', $problem['problem']);
        self::assertStringContainsString('application/x-www-form-urlencoded', $problem['fix']);
    }

    public function testTheOffendingBodyNeverReachesTheProblemContext(): void
    {
        // A request body carries passwords and tokens, and a problem report is
        // written to logs, to `--json` output and into issue trackers — so the
        // context says what went wrong with the body and never the body.
        $app = TestApp::boot(self::appDir());
        self::assertInstanceOf(App::class, $app);

        $body = '{"password":"hunter2","email":';
        $response = new TestResponse($app->handle(self::rawJson('POST', '/users', $body)));

        self::assertSame(400, $response->status());
        self::assertStringNotContainsString('hunter2', $response->body());
        self::assertArrayNotHasKey('body', $response->json()['problems'][0]['context']);
    }

    public function testAnEmptyBodyIsNotMalformedItIsSimplyMissing(): void
    {
        // An empty body is no body at all, so the answer is the validation
        // report naming every required field — not a parse error about an empty
        // string, which would be a fix for a problem the caller does not have.
        $app = TestApp::boot(self::appDir());
        self::assertInstanceOf(App::class, $app);

        $response = new TestResponse($app->handle(self::rawJson('POST', '/users', '')));

        self::assertSame(422, $response->status());
        self::assertSame('validation_failed', $response->json()['problems'][0]['code']);
    }

    public function testAnHtmlClientGetsAReadablePageForA422(): void
    {
        // The status and the diagnosis are the same; only the rendering differs,
        // which is what content negotiation is for.
        $response = self::client()->json(
            'POST',
            '/users',
            ['email' => 'nope', 'password' => 'nope', 'age' => 1, 'role' => 'nope', 'terms' => 'nope'],
            ['Accept' => 'text/html'],
        );

        self::assertSame(422, $response->status());
        self::assertStringContainsString('text/html', $response->header('Content-Type'));
        self::assertStringContainsString('FIX', $response->body());
    }

    public function testAnUnknownRouteIsStillA404NotAValidationProblem(): void
    {
        // The pack adds no routing behaviour; a 404 stays a 404.
        self::assertSame(404, self::client()->json('POST', '/nowhere', [])->status());
    }

    /** A request whose body is raw JSON and whose parsed body is deliberately unset. */
    private static function rawJson(string $method, string $path, string $body): ServerRequest
    {
        $request = new ServerRequest($method, $path, ['Content-Type' => 'application/json']);
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        return $request;
    }
}
