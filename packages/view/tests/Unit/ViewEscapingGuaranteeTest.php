<?php

declare(strict_types=1);

namespace Lava\View\Tests\Unit;

use Lava\View\Problem\AutoescapeDisabled;
use Lava\View\Problem\TemplateNotFound;
use Lava\View\Tests\Support\Templates;
use Lava\View\TwigFactory;
use Lava\View\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Extension\EscaperExtension;

/**
 * The two promises the pack makes that a security review found it was not
 * keeping (F2 and F3).
 *
 * Both are about the gap between what `TwigFactory`'s docblock says and what a
 * process can actually do: autoescaping was "not configurable" but one line
 * through the documented accessor switched it off for every later render, and
 * "every Twig failure leaves here as a LavaProblem" held for two of Twig's
 * three error classes.
 */
final class ViewEscapingGuaranteeTest extends TestCase
{
    private Templates $templates;

    protected function setUp(): void
    {
        $this->templates = Templates::make([
            'page.twig' => '<p>{{ value }}</p>',
            'layout.twig' => '<html>{% block body %}{% endblock %}</html>',
            'includes.twig' => "{% include 'partials/_nope.twig' %}",
            'themed.twig' => "{% extends '@' ~ theme ~ '/layout.twig' %}{% block body %}x{% endblock %}",
        ]);
    }

    protected function tearDown(): void
    {
        $this->templates->remove();
    }

    private function renderer(): ViewRenderer
    {
        return new ViewRenderer(
            TwigFactory::of($this->templates->dir(), '', true),
            $this->templates->dir(),
        );
    }

    public function testARenderIsRefusedOnceSomethingTurnsEscapingOff(): void
    {
        $renderer = $this->renderer();
        self::assertSame(
            '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
            $renderer->renderToString('page', ['value' => '<script>alert(1)</script>']),
        );

        // The line a Twig tutorial teaches, through the accessor this pack's own
        // docs recommend for adding a filter. The renderer is a singleton, so
        // before this it unescaped every later render in the process.
        $renderer->environment()
            ->getExtension(EscaperExtension::class)
            ->setDefaultStrategy(false);

        try {
            $renderer->renderToString('page', ['value' => '<script>alert(1)</script>']);
            self::fail('A render with escaping turned off must be refused.');
        } catch (AutoescapeDisabled $problem) {
            self::assertSame('autoescape_disabled', $problem->code());
            self::assertStringContainsString('page.twig', $problem->getMessage());
            self::assertStringContainsString('setDefaultStrategy', $problem->fix);
            self::assertSame('off', $problem->context['strategy']);
        }
    }

    public function testAPerTemplateOptOutIsStillAllowed(): void
    {
        // `{% autoescape false %}` is lexical: it is the documented way to write
        // a plain-text body, and it says nothing about any other template.
        $templates = Templates::make([
            'mail.txt.twig' => '{% autoescape false %}plain: {{ value }}{% endautoescape %}',
            'page.twig' => '<p>{{ value }}</p>',
        ]);

        try {
            $renderer = new ViewRenderer(TwigFactory::of($templates->dir(), '', true), $templates->dir());

            self::assertSame('plain: <b>x</b>', $renderer->renderToString('mail.txt', ['value' => '<b>x</b>']));
            self::assertSame('<p>&lt;b&gt;x&lt;/b&gt;</p>', $renderer->renderToString('page', ['value' => '<b>x</b>']));
        } finally {
            $templates->remove();
        }
    }

    public function testATemplateLoadedByATemplateFailsAsTemplateNotFound(): void
    {
        // A static include of a name that is not there: a typo, and the most
        // ordinary way to meet this. It used to leave the pack as a Twig
        // LoaderError and arrive as `unexpected_failure`.
        try {
            $this->renderer()->renderToString('includes');
            self::fail('A missing included template must be a LavaProblem.');
        } catch (TemplateNotFound $problem) {
            self::assertSame('template_not_found', $problem->code());
            self::assertStringContainsString('includes.twig', $problem->getMessage());
            self::assertStringContainsString('_nope.twig', $problem->getMessage());
            self::assertStringEndsWith('includes.twig', (string) $problem->source?->file);
            self::assertSame(1, $problem->source?->line);
        }
    }

    public function testTheDocumentedDynamicThemePatternFailsAsTemplateNotFound(): void
    {
        // `{% extends '@' ~ theme ~ '/layout.twig' %}` is what docs/packs/lava-view.md
        // teaches, with `theme` from the render context — so a bad value is an
        // ordinary consequence of the pattern, not a broken app.
        try {
            $this->renderer()->renderToString('themed', ['theme' => 'nope']);
            self::fail('An unknown namespace must be a LavaProblem.');
        } catch (TemplateNotFound $problem) {
            self::assertSame('template_not_found', $problem->code());
            self::assertStringContainsString('nope', $problem->getMessage());
            self::assertStringContainsString('ViewRenderer::namespaces()', $problem->fix);
        }
    }
}
