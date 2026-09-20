<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Http\DiagnosticsPage;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\Severity;
use Lava\Core\Problem\SourceLocation;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * What a production 5xx tells the client (security review, F4 — found
 * independently by three reviewers, through three different problems).
 *
 * Redaction used to blank `context` and `source` and send the problem's own
 * sentence and fix beside them, and several problems build those out of exactly
 * what redaction exists to withhold: the absolute template directory and the
 * app's whole template inventory, the database driver's message, an upstream
 * URL with its query string. A guard that withholds a fact in one field and
 * prints it in the next is not a guard.
 */
final class ProductionDisclosureTest extends TestCase
{
    /** A 5xx whose sentence and fix carry the server's internals, as real ones do. */
    private function leakyProblem(): LavaProblem
    {
        return new class (
            "No template 'zzz.twig' in /srv/www/shop/releases/9f2/views. Available: home.twig, admin/panel.twig.",
            'Create /srv/www/shop/releases/9f2/views/zzz.twig, or call render() with one of the names above.',
            ['directory' => '/srv/www/shop/releases/9f2/views'],
            SourceLocation::of('/srv/www/shop/releases/9f2/app/Services.php', 12),
        ) extends LavaProblem {
            public function code(): string
            {
                return 'test_leaky';
            }

            public function severity(): Severity
            {
                return Severity::Fatal;
            }
        };
    }

    /** @return array<string, mixed> */
    private function jsonProblem(LavaProblem $problem, string $env, string $accept = 'application/json'): array
    {
        $response = HttpErrors::toResponse($problem, new ServerRequest('GET', '/', ['Accept' => $accept]), $env);
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        return $body['problems'][0];
    }

    public function testAProductionServerFaultSendsItsCodeAndNothingElse(): void
    {
        $problem = $this->jsonProblem($this->leakyProblem(), 'prod');

        // The code is the field a client can act on, and the only one built
        // from a constant rather than from the app.
        self::assertSame('test_leaky', $problem['code']);
        self::assertSame('fatal', $problem['severity']);

        self::assertSame(HttpErrors::REDACTED_MESSAGE, $problem['problem']);
        self::assertSame(HttpErrors::REDACTED_FIX, $problem['fix']);
        self::assertSame([], (array) $problem['context']);
        self::assertNull($problem['source']);

        // The point of all of it: nothing about the server's filesystem.
        $body = json_encode($problem);
        self::assertIsString($body);
        self::assertStringNotContainsString('/srv/www', $body);
        self::assertStringNotContainsString('admin/panel.twig', $body);
    }

    public function testDevelopmentStillTellsTheWholeStory(): void
    {
        $problem = $this->jsonProblem($this->leakyProblem(), 'dev');

        self::assertStringContainsString('/srv/www/shop/releases/9f2/views', $problem['problem']);
        self::assertStringContainsString('/srv/www', $problem['fix']);
        self::assertSame('/srv/www/shop/releases/9f2/views', $problem['context']['directory']);
        self::assertStringEndsWith('app/Services.php', $problem['source']['file']);
    }

    public function testACallersOwnMistakeKeepsItsDiagnosisInProduction(): void
    {
        // A 4xx is the client's to fix, and the field, the rule and the fix are
        // what let an agent repair its request in one round trip.
        $badRequest = new class ('The request path contains a control character once decoded.', 'Send a path whose decoded bytes are printable.') extends LavaProblem {
            public function code(): string
            {
                return 'test_caller';
            }

            public function httpStatus(): int
            {
                return 400;
            }
        };

        $problem = $this->jsonProblem($badRequest, 'prod');

        self::assertStringContainsString('control character', $problem['problem']);
        self::assertStringContainsString('printable', $problem['fix']);
    }

    public function testTheBrowserPageWithholdsExactlyWhatTheJsonDoes(): void
    {
        $report = new ProblemReport();
        $report->add($this->leakyProblem());

        // The page renders message and fix for every problem, so without the
        // status it printed in prod what the JSON beside it withheld.
        $prod = DiagnosticsPage::render($report, 'prod', 500);
        self::assertStringNotContainsString('/srv/www', $prod);
        self::assertStringNotContainsString('admin/panel.twig', $prod);
        self::assertStringContainsString(HttpErrors::REDACTED_MESSAGE, $prod);
        self::assertStringContainsString('test_leaky', $prod, 'The code stays: it is what a reader reports.');

        $dev = DiagnosticsPage::render($report, 'dev', 500);
        self::assertStringContainsString('/srv/www/shop/releases/9f2/views', $dev);

        // A 4xx page keeps its sentence in production too.
        $notFound = new ProblemReport();
        $notFound->add(new class ('No route matches GET /nope.', 'Add the route, or fix the URL.') extends LavaProblem {
            public function code(): string
            {
                return 'test_caller';
            }
        });
        self::assertStringContainsString('No route matches', DiagnosticsPage::render($notFound, 'prod', 404));
    }

    public function testTheRedactedPageAndTheRedactedJsonSayTheSameThing(): void
    {
        // Two media, one decision: a reader comparing them must not find a
        // difference that is not there.
        $report = new ProblemReport();
        $report->add($this->leakyProblem());

        $html = DiagnosticsPage::render($report, 'prod', 500);
        $json = $this->jsonProblem($this->leakyProblem(), 'prod');

        self::assertStringContainsString(htmlspecialchars($json['problem'], ENT_QUOTES), $html);
        self::assertStringContainsString(htmlspecialchars($json['fix'], ENT_QUOTES), $html);
    }

    public function testAnUnstatedStatusRedactsRatherThanTells(): void
    {
        // The page's default: a caller that does not say which status it is
        // sending gets the safe answer, not the interesting one.
        $report = new ProblemReport();
        $report->add($this->leakyProblem());

        self::assertStringNotContainsString('/srv/www', DiagnosticsPage::render($report, 'prod'));
    }
}
