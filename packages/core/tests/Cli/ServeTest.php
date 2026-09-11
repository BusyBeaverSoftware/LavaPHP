<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Cli;

use Lava\Core\Tests\Support\ServedApp;
use PHPUnit\Framework\TestCase;

/**
 * Every route of every fixture, driven over REAL HTTP by `lava serve`.
 *
 * This is the coverage no in-process test can reach. RequestFactory::fromGlobals
 * only has meaning under a real SAPI; the Emitter writes real headers; config
 * and .env load in a fresh process that was not prepared by a test bootstrap;
 * and the Accept-negotiated diagnostics page is produced end to end. A green
 * run here is what makes the framework's HTTP claims true rather than
 * plausible.
 *
 * Four servers are started once for the class and shared by its tests. Each
 * gets its own free port, so the two module-app servers (pack on, pack off)
 * cannot see each other's routes — which is the whole point of the second one:
 * a gated-off pack must remove a route rather than merely hide it.
 */
final class ServeTest extends TestCase
{
    private static ServedApp $ok;
    private static ServedApp $module;
    private static ServedApp $moduleGatedOff;
    private static ServedApp $subject;

    public static function setUpBeforeClass(): void
    {
        self::$ok = ServedApp::start('ok-app');
        self::$module = ServedApp::start('module-app');
        // The pack gate is a real environment variable, so this is a second
        // process rather than a second request.
        self::$moduleGatedOff = ServedApp::start('module-app', ['LAVA_FEATURE_DEMO_PACK' => 'off']);
        self::$subject = ServedApp::start('subject-app');
    }

    public static function tearDownAfterClass(): void
    {
        self::$ok->stop();
        self::$module->stop();
        self::$moduleGatedOff->stop();
        self::$subject->stop();
    }

    // ── the serve handshake ─────────────────────────────────────────────────

    public function testTheServeEnvelopeNamesTheServerBeforeItServes(): void
    {
        // The envelope is a handshake, not a report: it is written before the
        // server takes the terminal, and it is the only reason a caller can know
        // the URL without guessing a port or sleeping.
        $data = self::$ok->data();

        self::assertSame('127.0.0.1', $data['host']);
        self::assertSame(self::$ok->port, $data['port']);
        self::assertSame("http://127.0.0.1:{$data['port']}", $data['url']);
        self::assertSame('public', $data['doc_root']);
        self::assertSame('public/index.php', $data['entry_point']);
        self::assertTrue($data['booted'], 'the server announced itself without booting');
    }

    // ── ok-app: routing, params, middleware, headers, negotiation ───────────

    public function testHealthIsServedAsJson(): void
    {
        $health = self::$ok->get('/health');

        self::assertSame(200, $health->status);
        self::assertSame('application/json', $health->header('content-type'));
        self::assertSame('{"status":"ok"}', $health->body);
    }

    public function testHeadReturnsHeadersAndNoBody(): void
    {
        // HEAD is declared on the same route as GET. A SAPI that buffered the
        // body and dropped it at the end would still pass this, but a router
        // that 405'd HEAD would not — which is the bug this catches.
        $head = self::$ok->request('HEAD', '/health');

        self::assertSame(200, $head->status);
        self::assertSame('', $head->body);
    }

    public function testATypedParameterArrivesAsAnInt(): void
    {
        // `{id:int}` is the difference between a router and a regex: the handler
        // is declared to take an int and receives one, so no handler ever casts
        // a string it should not have been given.
        $user = self::$ok->get('/users/42');

        self::assertSame(200, $user->status);
        $body = $user->json();
        self::assertSame(42, $body['user']['id'] ?? null);
    }

    public function testMiddlewareRunsInTheOrderItWasDeclared(): void
    {
        $body = self::$ok->get('/users/42')->json();

        self::assertSame(['global', 'auth'], $body['order'] ?? null);
    }

    public function testMiddlewareHeadersReachTheClient(): void
    {
        // Middleware that sets a header is only real if the header is on the
        // wire: an in-process test reads the response object, which would look
        // identical if the Emitter dropped every header it was given.
        $user = self::$ok->get('/users/42');

        self::assertSame('global', $user->header('x-timing'));
        self::assertSame('yes', $user->header('x-auth'));
    }

    public function testAServiceAndAParamAndTheDotEnvAllReachTheHandler(): void
    {
        // One body proving three separate mechanisms at once: the container
        // resolved App\Greeter, the router passed the `word` param, and config
        // read LAVA_APP_NAME from config/.env in this fresh process.
        $greet = self::$ok->get('/greet/ada');

        self::assertSame(200, $greet->status);
        self::assertSame('[prod] Hello, ada!', $greet->body);
    }

    public function testAFlaggedRouteIsServedWhenItsFlagIsOn(): void
    {
        self::assertSame(200, self::$ok->get('/beta/dashboard')->status);
    }

    public function testARejectedParamIsARouteNotFoundProblem(): void
    {
        // `/users/abc` cannot match `{id:int}`, so it is not a 400 — the route
        // does not exist for that path. The body must say so in the machine
        // vocabulary, not in prose.
        $notFound = self::$ok->get('/users/abc');

        self::assertSame(404, $notFound->status);
        self::assertSame('route_not_found', $notFound->problemCode());
        self::assertStringContainsString('lava routes --json', (string) $notFound->problem()['fix']);
    }

    public function testNoAcceptHeaderDefaultsToJson(): void
    {
        // An agent's HTTP client often sends no Accept at all; defaulting to the
        // diagnostics page would hand it HTML to parse.
        self::assertSame('application/json', self::$ok->get('/users/abc')->header('content-type'));
    }

    public function testAcceptHtmlNegotiatesTheDiagnosticsPage(): void
    {
        // The same 404, rendered for a human: same status, same code, same fix,
        // different content type. The status must NOT change — the negotiation
        // is about presentation, never about what happened.
        $html = self::$ok->get('/users/abc', ['Accept' => 'text/html']);

        self::assertSame(404, $html->status);
        self::assertStringStartsWith('text/html', (string) $html->header('content-type'));
        self::assertStringContainsString('route_not_found', $html->body);
        self::assertStringContainsString('lava routes --json', $html->body);
    }

    public function testAWrongMethodIsAMethodNotAllowedWithAllow(): void
    {
        // 405 with `Allow` is what tells a caller which verb to retry with; a
        // plain 404 here would send it looking for a route that exists.
        $notAllowed = self::$ok->request('POST', '/users/42');

        self::assertSame(405, $notAllowed->status);
        self::assertSame('GET', $notAllowed->header('allow'));
        self::assertSame('method_not_allowed', $notAllowed->problemCode());
    }

    // ── module-app: a pack's route, and the pack gated off ──────────────────

    public function testAPackRouteIsServed(): void
    {
        $quota = self::$module->get('/demo/quota');

        self::assertSame(200, $quota->status);
        // The handler reads its own service out of the container, so a 200 with
        // the right number proves the pack's register() ran too, not just its
        // routes().
        self::assertSame(['limit' => 42], $quota->json());
    }

    public function testTheAppsOwnRouteIsUnaffectedByThePack(): void
    {
        self::assertSame(200, self::$module->get('/')->status);
    }

    public function testAGatedOffPackRemovesItsRouteEntirely(): void
    {
        // "Off" has to mean the route is absent, not that the handler refuses:
        // an agent reading `lava routes` on a gated-off app must see the truth,
        // and a 403 here would be a lie about the shape of the app.
        $quota = self::$moduleGatedOff->get('/demo/quota');

        self::assertSame(404, $quota->status);
        self::assertSame('route_not_found', $quota->problemCode());
    }

    public function testAGatedOffPackLeavesTheAppsRoutesAlone(): void
    {
        self::assertSame(200, self::$moduleGatedOff->get('/')->status);
    }

    // ── subject-app: audience gating ────────────────────────────────────────

    public function testTheGatedAppIsHealthy(): void
    {
        self::assertSame(200, self::$subject->get('/health')->status);
    }

    public function testAnInAudienceSubjectReachesTheGatedRoute(): void
    {
        // The audience resolver reads X-User-Id, so the same path answers
        // differently for two callers — which is the only way to tell audience
        // gating apart from a route that is simply missing.
        $pilot = self::$subject->get('/team/board', ['X-User-Id' => 'u1']);

        self::assertSame(200, $pilot->status);
        self::assertSame(['board' => 'team.board'], $pilot->json());
    }

    public function testASubjectOutsideTheAudienceGetsARealFourOhFour(): void
    {
        self::assertSame(404, self::$subject->get('/team/board', ['X-User-Id' => 'u2'])->status);
    }

    public function testAnAnonymousSubjectGetsARealFourOhFour(): void
    {
        self::assertSame(404, self::$subject->get('/team/board')->status);
    }

    public function testARolloutGateAdmitsAnIdentifiedSubject(): void
    {
        // A percentage rollout needs a stable identity to hash; without one the
        // caller is simply outside it.
        self::assertSame(200, self::$subject->get('/early', ['X-User-Id' => 'z9'])->status);
    }

    public function testARolloutGateExcludesAnonymousCallers(): void
    {
        self::assertSame(404, self::$subject->get('/early')->status);
    }
}
