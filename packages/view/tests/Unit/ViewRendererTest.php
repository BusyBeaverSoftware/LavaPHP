<?php

declare(strict_types=1);

namespace Lava\View\Tests\Unit;

use Lava\Core\Problem\LavaProblem;
use Lava\View\Problem\BadViewCall;
use Lava\View\Problem\TemplateFailed;
use Lava\View\Problem\TemplateNotFound;
use Lava\View\Tests\Support\Templates;
use Lava\View\TwigFactory;
use Lava\View\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The renderer's own rules, without a container or an app around them.
 *
 * Three of these are decisions rather than mechanics and would each be a
 * silent bug if reversed: the extension is optional and both spellings are the
 * same template, a missing template lists what is there, and a `LavaProblem`
 * raised inside a template survives Twig's wrapping instead of being flattened
 * into a generic "the template failed".
 */
final class ViewRendererTest extends TestCase
{
    private Templates $templates;

    protected function setUp(): void
    {
        $this->templates = Templates::make([
            'page.twig' => '<h1>{{ title }}</h1>',
            'tasks/show.twig' => '<p>show</p>',
            'untyped.twig' => '<p>{{ never_passed }}</p>',
            'broken.twig' => "{% if title %}\n<h1>{{ title }}</h1>",
            'boom.twig' => '<p>{{ boom() }}</p>',
            // Not a template: it must not appear in the available list, or the
            // list would offer the reader a name render() cannot take.
            'notes.txt' => 'not a template',
        ]);
    }

    protected function tearDown(): void
    {
        $this->templates->remove();
    }

    /** A renderer with one extra function, `boom()`, that raises a LavaProblem. */
    private function renderer(bool $withBoom = false): ViewRenderer
    {
        $twig = TwigFactory::of($this->templates->dir(), '', true);

        if ($withBoom) {
            $twig->addFunction(new TwigFunction('boom', static function (): string {
                throw BadViewCall::routeName(null);
            }));
        }

        return new ViewRenderer($twig, $this->templates->dir());
    }

    public function testTheExtensionIsOptionalAndBothSpellingsAreTheSameTemplate(): void
    {
        // One rule, not two ways to do a thing: `normalize()` adds `.twig` when
        // it is missing, so the name in a problem message and the name in the
        // call site can be the same string.
        $renderer = $this->renderer();

        self::assertSame('<h1>hi</h1>', $renderer->renderToString('page', ['title' => 'hi']));
        self::assertSame('<h1>hi</h1>', $renderer->renderToString('page.twig', ['title' => 'hi']));
    }

    public function testASubdirectoryIsPartOfTheName(): void
    {
        // The rule that catches people: a file at views/tasks/show.twig is
        // 'tasks/show', not 'show'.
        self::assertSame('<p>show</p>', $this->renderer()->renderToString('tasks/show'));
    }

    public function testExistsAcceptsBothSpellingsAndSaysNoToAnythingElse(): void
    {
        // A handler uses this to choose between rendering a page and returning
        // a 404 — without catching an exception to find out.
        $renderer = $this->renderer();

        self::assertTrue($renderer->exists('page'));
        self::assertTrue($renderer->exists('page.twig'));
        self::assertTrue($renderer->exists('tasks/show'));
        self::assertFalse($renderer->exists('nope'));
        // A real file that is not a template is not renderable, and saying it
        // exists would push the failure one call later with a worse message.
        self::assertFalse($renderer->exists('notes.txt'));
    }

    public function testRenderBuildsAnHtmlResponse(): void
    {
        $response = $this->renderer()->render('page', ['title' => 'hi']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>hi</h1>', (string) $response->getBody());
    }

    public function testRenderStatusCarriesTheStatusAndStillRenders(): void
    {
        // The 404 page and the 422 re-render: same template, different status.
        // This is why the renderer returns a response rather than a string —
        // otherwise every app reinvents it and gets the header slightly wrong.
        $response = $this->renderer()->renderStatus('page', 422, ['title' => 'bad']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('<h1>bad</h1>', (string) $response->getBody());
    }

    public function testTemplateDirAndEnvironmentAreReachable(): void
    {
        // `templateDir()` because a problem message has to name it and
        // `lava describe` should be able to answer it; `environment()` so an
        // app can add a filter of its own without the pack growing a
        // config surface for it.
        $renderer = $this->renderer();

        self::assertSame($this->templates->dir(), $renderer->templateDir());
        self::assertSame($this->templates->dir(), $renderer->environment()->getLoader()->getPaths()[0]);
    }

    public function testAMissingTemplateListsWhatIsThereWithExtensions(): void
    {
        try {
            $this->renderer()->renderToString('does/not/exist');
        } catch (TemplateNotFound $problem) {
            self::assertSame('template_not_found', $problem->code());
            // The name looked for is normalized, so it names the file it would
            // have opened — not the string the caller typed.
            self::assertStringContainsString("'does/not/exist.twig'", $problem->getMessage());

            /** @var list<string> $available */
            $available = $problem->context['available'];
            self::assertContains('page.twig', $available);
            self::assertContains('tasks/show.twig', $available);
            self::assertNotContains('notes.txt', $available);
            // Sorted, so two identical failures produce identical text.
            $sorted = $available;
            sort($sorted);
            self::assertSame($sorted, $available);

            return;
        }

        self::fail('a missing template should raise template_not_found');
    }

    public function testTheFixNamesTheFileToCreateExactlyOnce(): void
    {
        // The renderer normalizes before looking, so the name handed to the
        // problem already ends in `.twig`. A fix that appended the extension
        // again would tell its reader to create `does/not/exist.twig.twig` —
        // a file the loader would still not find.
        try {
            $this->renderer()->renderToString('does/not/exist');
        } catch (TemplateNotFound $problem) {
            self::assertStringContainsString(
                $this->templates->dir() . '/does/not/exist.twig',
                $problem->fix,
            );
            self::assertStringNotContainsString('.twig.twig', $problem->fix);

            return;
        }

        self::fail('a missing template should raise template_not_found');
    }

    public function testAMissingNamespacedTemplateNamesThatNamespacesDirectory(): void
    {
        // Lava Notes (R2-B15): a theme added as a Twig namespace was reported
        // with only the pack's main directory — the wrong place to look.
        $theme = Templates::make(['layout.twig' => 'theme', 'parts/nav.twig' => 'nav']);
        try {
            $twig = TwigFactory::of($this->templates->dir(), '', true);
            $loader = $twig->getLoader();
            self::assertInstanceOf(FilesystemLoader::class, $loader);
            $loader->addPath($theme->dir(), 'theme');

            (new ViewRenderer($twig, $this->templates->dir()))->renderToString('@theme/missing');
            self::fail('a missing template should raise template_not_found');
        } catch (TemplateNotFound $problem) {
            self::assertStringStartsWith("No template '@theme/missing.twig' in {$theme->dir()}.", $problem->getMessage());
            self::assertSame($theme->dir(), $problem->context['directory']);
            self::assertSame(['@theme/layout.twig', '@theme/parts/nav.twig'], $problem->context['available']);
            self::assertStringContainsString($theme->dir() . '/missing.twig', $problem->fix);
            self::assertStringNotContainsString($this->templates->dir() . '/', $problem->getMessage() . $problem->fix);
        } finally {
            $theme->remove();
        }
    }

    public function testAnEmptyDirectorySaysSoRatherThanListingNothing(): void
    {
        // An empty list and a wrong spelling need different fixes: one means
        // "write the template", the other "read the list". The message has to
        // tell them apart, so it says which case it is.
        $empty = Templates::empty();
        try {
            $renderer = new ViewRenderer(TwigFactory::of($empty->dir(), '', true), $empty->dir());
            $renderer->renderToString('page');
        } catch (TemplateNotFound $problem) {
            self::assertStringContainsString('directory is empty', $problem->getMessage());
            self::assertSame([], $problem->context['available']);

            return;
        } finally {
            $empty->remove();
        }

        self::fail('a missing template should raise template_not_found');
    }

    public function testATemplateThatDoesNotCompileKeepsItsFileAndLine(): void
    {
        try {
            $this->renderer()->renderToString('broken');
        } catch (TemplateFailed $problem) {
            self::assertSame('template_failed', $problem->code());
            self::assertStringContainsString('does not compile', $problem->getMessage());
            self::assertNotNull($problem->source);
            // Twig already computed both of these. The whole job of this
            // wrapper is to keep them instead of letting them become
            // "a Twig error escaped".
            self::assertSame($this->templates->dir() . '/broken.twig', $problem->source->file);
            self::assertGreaterThan(1, $problem->source->line);

            return;
        }

        self::fail('a template that does not compile should raise template_failed');
    }

    public function testARuntimeErrorNamesTheHandlerBecauseThatIsUsuallyTheFault(): void
    {
        try {
            $this->renderer()->renderToString('untyped');
        } catch (TemplateFailed $problem) {
            self::assertSame('template_failed', $problem->code());
            self::assertStringContainsString('never_passed', $problem->getMessage());
            // Not "fix your template": with strict_variables on, this is almost
            // always a context the handler forgot, and sending the reader to
            // the template would send them to the wrong file.
            self::assertStringContainsString('render() in the handler', $problem->fix);
            self::assertStringContainsString("never_passed|default('')", $problem->fix);

            return;
        }

        self::fail('an undefined variable should raise template_failed');
    }

    public function testAProblemRaisedInsideATemplatePassesThroughUntouched(): void
    {
        // Twig wraps whatever a template function throws in a RuntimeError
        // whose `previous` is the original. The specific diagnosis — with its
        // own code and its own fix — is the useful half, so it is unwrapped and
        // rethrown as itself. Wrapping it would turn `bad_view_call` into
        // `template_failed` and bury the fix inside Twig's sentence.
        try {
            $this->renderer(withBoom: true)->renderToString('boom');
        } catch (LavaProblem $problem) {
            self::assertInstanceOf(BadViewCall::class, $problem);
            self::assertSame('bad_view_call', $problem->code());
            self::assertStringContainsString('route name', $problem->getMessage());

            return;
        }

        self::fail('a LavaProblem raised in a template should reach the caller as itself');
    }
}
