<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\BootFailure;
use Lava\Core\Http\DiagnosticsPage;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Http\Responses;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\MethodNotAllowed;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\RouteNotFound;
use Lava\Core\Problem\Severity;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP edge: response factories, problem→response mapping (status codes,
 * Accept negotiation, the Allow header), and the escaping of the diagnostics
 * page.
 */
final class HttpTest extends TestCase
{
    public function testResponseFactories(): void
    {
        $json = Responses::json(['a' => 1, 'b' => [2, 3]]);
        self::assertSame(200, $json->getStatusCode());
        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));
        self::assertSame(['a' => 1, 'b' => [2, 3]], json_decode((string) $json->getBody(), true));

        $text = Responses::text('hello', 201);
        self::assertSame(201, $text->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $text->getHeaderLine('Content-Type'));
        self::assertSame('hello', (string) $text->getBody());

        $html = Responses::html('<p>hi</p>');
        self::assertSame('text/html; charset=utf-8', $html->getHeaderLine('Content-Type'));

        $redirect = Responses::redirect('/login');
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame('/login', $redirect->getHeaderLine('Location'));

        $empty = Responses::noContent();
        self::assertSame(204, $empty->getStatusCode());
        self::assertSame('', (string) $empty->getBody());
    }

    public function testProblemsMapToStatusCodes(): void
    {
        $notFound = HttpErrors::toResponse(RouteNotFound::of('GET', '/nope'));
        self::assertSame(404, $notFound->getStatusCode());

        $notAllowed = HttpErrors::toResponse(MethodNotAllowed::of('POST', '/users/42', ['GET', 'PUT']));
        self::assertSame(405, $notAllowed->getStatusCode());
        self::assertSame('GET, PUT', $notAllowed->getHeaderLine('Allow')); // RFC 9110 §15.5.5

        $other = HttpErrors::toResponse($this->problem('It broke.'));
        self::assertSame(500, $other->getStatusCode());
    }

    public function testProblemBodiesCarryCodeAndFix(): void
    {
        $response = HttpErrors::toResponse(RouteNotFound::of('GET', '/nope'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('route_not_found', $body['problems'][0]['code']);
        self::assertStringContainsString('lava routes --json', $body['problems'][0]['fix']);
    }

    public function testAcceptNegotiation(): void
    {
        $problem = $this->problem('It broke.');

        $jsonFirst = HttpErrors::toResponse($problem, new ServerRequest('GET', '/', ['Accept' => 'application/json,text/html']));
        self::assertSame('application/json', $jsonFirst->getHeaderLine('Content-Type'));

        $htmlFirst = HttpErrors::toResponse($problem, new ServerRequest('GET', '/', ['Accept' => 'text/html,application/json']));
        self::assertStringContainsString('text/html', $htmlFirst->getHeaderLine('Content-Type'));

        $wildcard = HttpErrors::toResponse($problem, new ServerRequest('GET', '/', ['Accept' => '*/*']));
        self::assertSame('application/json', $wildcard->getHeaderLine('Content-Type'));

        $none = HttpErrors::toResponse($problem, new ServerRequest('GET', '/'));
        self::assertSame('application/json', $none->getHeaderLine('Content-Type'));
    }

    public function testDiagnosticsPageEscapesAndHidesContextInProd(): void
    {
        $report = new ProblemReport();
        $report->add($this->problem('XSS <script>alert(1)</script> attempt', ['secret_context_key' => 'value']));

        $dev = DiagnosticsPage::render($report, 'dev');
        self::assertStringContainsString('&lt;script&gt;', $dev);
        self::assertStringNotContainsString('<script>', $dev);
        self::assertStringContainsString('secret_context_key', $dev);

        $prod = DiagnosticsPage::render($report, 'prod');
        self::assertStringNotContainsString('secret_context_key', $prod);
        self::assertStringContainsString('FIX', $prod);
    }

    public function testBootFailureRendersAsAResponse(): void
    {
        $report = new ProblemReport();
        $report->add(RouteNotFound::of('GET', '/x'));
        $failure = new BootFailure($report, '/app', 'dev');

        $response = $failure->toResponse();
        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('route_not_found', $body['problems'][0]['code']);
    }

    /** A minimal concrete problem for response-shape tests. */
    private function problem(string $message, array $context = []): LavaProblem
    {
        return new class($message, 'Fix it.', $context) extends LavaProblem {
            public function code(): string
            {
                return 'test_http';
            }

            public function severity(): Severity
            {
                return Severity::Fatal;
            }
        };
    }
}