<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\BootedTestCase;
use Lava\View\ViewRenderer;

/**
 * The public surface: what anyone sees without signing in.
 *
 * Each test here corresponds to a claim the app makes that a reader of `views/`
 * cannot check by looking — that a title from a stranger is escaped, that a
 * draft is invisible rather than merely unlinked, that a link `url()` produces is
 * a path the router would actually match, that every 4xx a browser can meet is
 * this app's page and not the framework's.
 */
final class PagesTest extends BootedTestCase
{
    public function testTheHomePageRendersForAStranger(): void
    {
        $response = $this->browser()->get('/');

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('text/html', $response->header('Content-Type'));
        self::assertStringContainsString('Nothing published yet.', $response->body());
    }

    public function testAPostBodyFromAClientIsEscaped(): void
    {
        // The value would be dangerous unescaped, which is what makes this an
        // assertion about safety rather than about formatting. A blog body is
        // arbitrary text from whoever wrote it, and `|raw` in the template would
        // turn every post into an XSS hole — so the escaping is the feature and
        // this is the test that keeps it.
        $browser = $this->signedIn();
        $id = $this->createPost($browser, 'A post', '<script>alert(1)</script>');

        $body = $this->browser()->get("/posts/{$id}")->body();

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    public function testTheListLinksToEachPostByRouteName(): void
    {
        $browser = $this->signedIn();
        $id = $this->createPost($browser, 'Ship it');

        $body = $this->browser()->get('/')->body();

        // The literal path `url()` produced, not the route name it was given: a
        // link is only correct if it is what the router would match.
        self::assertStringContainsString("href=\"/posts/{$id}\"", $body);
        self::assertStringContainsString('Ship it', $body);
    }

    public function testADraftIsInvisibleToAStrangerRatherThanMerelyUnlinked(): void
    {
        $author = $this->signedIn();
        // No `published` field at all: an unchecked checkbox is absent from a
        // form body, which is exactly what a browser sends. The draft default
        // has to survive that, so the absent field is the case worth testing
        // rather than `published=0`.
        $id = $this->createPost($author, 'A private draft', 'Not for you.', published: false);

        $stranger = $this->browser();

        // Not on the list, and — the part that matters — not readable directly
        // either. Hiding a row from a list while leaving it at its id is not
        // privacy, it is a hint.
        self::assertStringNotContainsString('A private draft', $stranger->get('/')->body());
        self::assertSame(404, $stranger->get("/posts/{$id}")->status());

        // The author still sees it, so the 404 above is about the viewer and not
        // about the row.
        self::assertSame(200, $author->get("/posts/{$id}")->status());
    }

    public function testAnUnknownPostIsTheAppsOwnPageForABrowser(): void
    {
        $response = $this->browser()->get('/posts/999');

        self::assertSame(404, $response->status());
        self::assertStringStartsWith('text/html', $response->header('Content-Type'));

        // This app's layout, not the framework's diagnostics page: the heading
        // is ours, the chrome is ours, and the problem code — a fact about this
        // app's error taxonomy, not something a reader can use — is absent.
        self::assertStringContainsString('Not found', $response->body());
        self::assertStringContainsString('Lava Blog', $response->body());
        self::assertStringNotContainsString('post_not_found', $response->body());
        // The `fix` is written for whoever maintains the app and reads as noise
        // to a visitor, so it must not appear on a page a visitor sees.
        self::assertStringNotContainsString('a draft is only visible to the user who wrote it', $response->body());
    }

    public function testAMistypedAddressIsTheAppsOwnPageToo(): void
    {
        // No route matches, so no handler runs — but `lava/core` throws the 404
        // through the global middleware all the same, and this app's error page
        // is one of those layers. A mistyped URL and a missing post are the same
        // situation to a reader, and they now look it.
        $response = $this->browser()->get('/no/such/page');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Not found', $response->body());
        self::assertStringContainsString('Lava Blog', $response->body());
        self::assertStringNotContainsString('LavaPHP found', $response->body());
        self::assertStringNotContainsString('route_not_found', $response->body());
    }

    public function testTheWrongMethodIsTheAppsOwnPageAndStillSaysWhatIsAllowed(): void
    {
        // Signing out is POST-only (a GET logout is fireable by an `<img>` on any
        // site), so a GET is a 405 — and a 405 rendered by this app instead of the
        // framework still has to say which method would have worked.
        $response = $this->browser()->get('/logout');

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->header('Allow'));
        self::assertStringContainsString('Lava Blog', $response->body());
    }

    public function testTheSameMissingPostIsJsonForAnAgent(): void
    {
        // `Browser` sends a browser's `Accept` by default — it is a browser — so
        // acting as an agent means overriding it rather than merely omitting it.
        // An `Accept` naming neither media is the case `curl` sends, and the
        // framework's rule reads it as an agent.
        $response = $this->browser()->get('/posts/999', ['Accept' => '*/*']);

        self::assertSame(404, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('post_not_found', $this->firstProblemCode($response->json()));
    }

    public function testAMistypedAddressIsStillJsonForAnAgent(): void
    {
        // The error page re-throws anything that did not ask for HTML, so the
        // unrouted 404 an agent gets is the framework's envelope, unchanged.
        $response = $this->browser()->get('/no/such/page', ['Accept' => '*/*']);

        self::assertSame(404, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('route_not_found', $this->firstProblemCode($response->json()));
    }

    public function testTheTemplatesTheAppNamesAreTheTemplatesThatExist(): void
    {
        // A renamed template is otherwise a runtime error on the one page that
        // used it, found by whoever visits that page first.
        $view = self::$app->container->get(ViewRenderer::class);
        self::assertInstanceOf(ViewRenderer::class, $view);

        foreach (['layout', 'home', 'post', 'editor', 'login', 'register', 'error'] as $template) {
            self::assertTrue($view->exists($template), "views/{$template}.twig is missing");
        }

        self::assertFalse($view->exists('does/not/exist'));
    }

    private function firstProblemCode(mixed $decoded): string
    {
        self::assertIsArray($decoded);
        $problems = $decoded['problems'];
        self::assertIsArray($problems);
        $first = $problems[0];
        self::assertIsArray($first);
        self::assertIsString($first['code']);

        return $first['code'];
    }
}
