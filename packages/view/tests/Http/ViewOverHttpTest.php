<?php

declare(strict_types=1);

namespace Lava\View\Tests\Http;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Lava\Core\Testing\TestResponse;
use PHPUnit\Framework\TestCase;

/**
 * The pack over real HTTP, through the fixture app.
 *
 * Unit tests can show that `TwigFactory` sets an option and that
 * `ViewFunctions` narrows an argument. What they cannot show is the thing the
 * pack actually promises: that a template reaches the browser escaped, that
 * `url()` produces the path the router would match, that `feature()` and a
 * route's `->when()` agree, and that a template mistake arrives as a
 * `LavaProblem` with a code and a fix rather than as a stack trace. Those are
 * differences a request makes and a function call does not.
 *
 * Every route here is a separate GET on purpose: the fixture is the executable
 * spec of the pack's failure modes, and a spec that needs a constructed request
 * body is a spec nobody reads.
 */
final class ViewOverHttpTest extends TestCase
{
    private static function appDir(): string
    {
        return dirname(__DIR__) . '/fixtures/apps/view-app';
    }

    /** @param array<string, string> $env */
    private static function client(array $env = []): TestClient
    {
        return new TestClient(self::booted($env));
    }

    /**
     * The fixture, booted — with the boot problems in the failure message.
     *
     * A bare `assertInstanceOf` here would report "expected App, got
     * BootFailure" for every test in the file and say nothing about WHY, so one
     * wiring mistake would look like twelve unrelated failures. The problems
     * carry the code, the file and the fix, which is the whole point of them.
     *
     * @param array<string, string> $env
     */
    private static function booted(array $env = []): App
    {
        $app = TestApp::boot(self::appDir(), $env);
        if ($app instanceof BootFailure) {
            $lines = [];
            foreach ($app->problems->problems() as $problem) {
                $lines[] = $problem->code() . ': ' . $problem->getMessage()
                    . ' — ' . $problem->fix
                    . ($problem->source === null ? '' : ' (' . $problem->source . ')');
            }
            self::fail("the fixture app did not boot:\n  " . implode("\n  ", $lines));
        }

        return $app;
    }

    /** @return array<string, mixed> */
    private static function problem(TestResponse $response): array
    {
        $body = json_decode($response->body(), true);
        self::assertIsArray($body, 'an error response is JSON');

        $problems = $body['problems'] ?? null;
        self::assertIsArray($problems);
        self::assertNotEmpty($problems);

        $first = $problems[0];
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    public function testAPageRendersThroughTheWholeStack(): void
    {
        $response = self::client()->get('/page');

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));

        $html = $response->body();
        // The include resolved, and a template in a subdirectory is named by
        // its path — 'partials/_nav', not '_nav'.
        self::assertStringContainsString('<nav>home</nav>', $html);
        self::assertStringContainsString('<h1>', $html);
    }

    public function testTheContextIsEscapedByDefault(): void
    {
        // The title the handler passes is literally `<script>alert(1)</script>`.
        // Escaping has to be the default rather than an opt-in `|e`, because the
        // failure mode of getting it wrong is an XSS hole that no other test in
        // this suite would notice.
        $html = self::client()->get('/page')->body();

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testUrlReversesARouteName(): void
    {
        // Not `/users/{id}` and not a hardcoded path: the router's own
        // generator turned the name plus a param into the path it would match.
        self::assertStringContainsString('<a href="/users/7">', self::client()->get('/page')->body());
    }

    public function testFeatureAgreesWithTheFlagResolver(): void
    {
        // On: config/features.php sets it. Off: the environment beats the file.
        // Both go through the same resolver a route's ->when() uses, so a gated
        // page and a gated route cannot disagree — which is the point of the
        // function existing rather than a `{% if some_boolean %}`.
        self::assertSame('shown', trim(self::client()->get('/gated')->body()));
        self::assertSame('hidden', trim(self::client(['LAVA_FEATURE_BETA_BANNER' => 'off'])->get('/gated')->body()));
    }

    public function testTheBannerFollowsTheFlagInARealTemplate(): void
    {
        $on = self::client()->get('/page')->body();
        self::assertStringContainsString('class="banner"', $on);

        $off = self::client(['LAVA_FEATURE_BETA_BANNER' => 'off'])->get('/page')->body();
        self::assertStringNotContainsString('class="banner"', $off);
        // The rest of the page is untouched: the flag hid one element, it did
        // not fail the render.
        self::assertStringContainsString('<a href="/users/7">', $off);
    }

    public function testRenderStatusCarriesTheStatus(): void
    {
        $response = self::client()->get('/status');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('<h1>gone</h1>', $response->body());
    }

    public function testAMissingTemplateNamesTheDirectoryAndWhatIsInIt(): void
    {
        $response = self::client()->get('/missing');
        self::assertSame(500, $response->status());

        $problem = self::problem($response);
        self::assertSame('template_not_found', $problem['code']);
        self::assertStringContainsString("'does/not/exist.twig'", (string) $problem['problem']);
        self::assertStringContainsString(self::appDir() . '/views', (string) $problem['problem']);

        // The list is the fix. Without it the reader is guessing at a spelling;
        // with it, `page.twig` and `partials/_nav.twig` are right there — and
        // they are written the way render() takes them, extension and all.
        $available = $problem['context']['available'] ?? null;
        self::assertIsArray($available);
        self::assertContains('page.twig', $available);
        self::assertContains('partials/_nav.twig', $available);

        // The list reaches the message too, not only the machine-readable
        // context: the terminal renderer shows the message, and a fix that says
        // "call render() with one of the names above" over a message that lists
        // no names is worse than no fix at all.
        self::assertStringContainsString('Available: ', (string) $problem['problem']);
        self::assertStringContainsString('page.twig', (string) $problem['problem']);

        // And the file the fix tells you to create is the file the loader
        // looked for — once. The renderer normalizes the name before the
        // lookup, so a fix that appended `.twig` again would send its reader to
        // create `does/not/exist.twig.twig`, which would not fix anything.
        self::assertStringContainsString(self::appDir() . '/views/does/not/exist.twig', (string) $problem['fix']);
        self::assertStringNotContainsString('.twig.twig', (string) $problem['fix']);
    }

    public function testATemplateThatDoesNotCompileIsAProblemWithItsLine(): void
    {
        $response = self::client()->get('/broken');
        self::assertSame(500, $response->status());

        $problem = self::problem($response);
        self::assertSame('template_failed', $problem['code']);
        self::assertStringContainsString('does not compile', (string) $problem['problem']);

        // Twig already knew the file and the line; the whole job of this wrapper
        // is to keep them instead of turning them into "a Twig error escaped".
        $source = $problem['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame(self::appDir() . '/views/broken.twig', $source['file']);
        self::assertIsInt($source['line']);
        self::assertGreaterThan(0, $source['line']);
    }

    public function testAnUndefinedVariableFailsLoudlyRatherThanRenderingBlank(): void
    {
        // strict_variables, from the outside. With Twig's default the page would
        // render with an empty <p> and nothing anywhere would say a value was
        // missing — the single hardest template defect to notice.
        $response = self::client()->get('/untyped');
        self::assertSame(500, $response->status());

        $problem = self::problem($response);
        self::assertSame('template_failed', $problem['code']);
        self::assertStringContainsString('never_passed', (string) $problem['problem']);
        // The fix names the handler, not the template: this is usually a
        // context the handler forgot, and "fix your template" would send the
        // reader to the wrong file.
        self::assertStringContainsString('render() in the handler', (string) $problem['fix']);
    }

    public function testAUrlParamThatCannotBeInAUrlIsReportedAsItsOwnProblem(): void
    {
        // The object case: `{{ url('users.show', {id: user}) }}`.
        $response = self::client()->get('/bad-url');
        self::assertSame(500, $response->status());

        $problem = self::problem($response);
        // NOT template_failed. Twig wraps anything a template function throws,
        // and unwrapping it here is what keeps the specific code — and the
        // specific fix — instead of burying it in Twig's own sentence.
        self::assertSame('bad_view_call', $problem['code']);
        self::assertStringContainsString('stdClass', (string) $problem['problem']);
        self::assertStringContainsString('item.id', (string) $problem['fix']);
    }

    public function testANullParamGetsTheGuardTheLinkFix(): void
    {
        // The case that actually happens in an app: the record was never
        // loaded, so the value is null rather than missing.
        $problem = self::problem(self::client()->get('/bad-param'));

        self::assertSame('bad_view_call', $problem['code']);
        self::assertStringContainsString('null', (string) $problem['problem']);
        self::assertStringContainsString('guard the link', (string) $problem['fix']);
    }

    public function testTheRendererIsInjectableLikeAnyOtherService(): void
    {
        // The pack's whole container surface is one id. A handler type-hints
        // ViewRenderer and it arrives — no static helper, no global.
        $app = self::booted();

        $view = $app->container->get(\Lava\View\ViewRenderer::class);
        self::assertInstanceOf(\Lava\View\ViewRenderer::class, $view);
        self::assertSame($view, $app->container->get(\Lava\View\ViewRenderer::class));
        self::assertSame(self::appDir() . '/views', $view->templateDir());
        self::assertTrue($view->exists('page'));
        self::assertFalse($view->exists('nope'));
    }

    public function testThePacksOwnConfigFileIsReadAndItsProvenanceRecorded(): void
    {
        // `ViewModule::pack()` declares `configFiles: ['view']`, and this is
        // the only thing that proves the declaration is not dead: the loader
        // read config/view.php, keyed it as `view.path`, and recorded where the
        // value came from — which is what `lava config` prints. Without this,
        // the pack could name a config file nothing ever reads and every test
        // in this file would still pass.
        $app = self::booted();

        self::assertSame('views', $app->config->string('view.path', '(unset)'));
        self::assertSame('config/view.php', $app->config->provenance('view.path'));
    }
}
