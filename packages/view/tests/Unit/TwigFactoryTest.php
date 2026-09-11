<?php

declare(strict_types=1);

namespace Lava\View\Tests\Unit;

use Lava\View\Tests\Support\Templates;
use Lava\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Error\RuntimeError;

/**
 * The three decisions in TwigFactory, asserted where they can actually be
 * observed.
 *
 * `autoescape` and `strict_variables` are checked by rendering rather than by
 * reading the option back: the option is an implementation detail of how Twig
 * is configured, and what the pack promises is the behaviour. A future Twig
 * version that renamed the option while keeping the meaning would keep these
 * tests green and a test asserting on the array would not.
 */
final class TwigFactoryTest extends TestCase
{
    private Templates $templates;

    protected function setUp(): void
    {
        $this->templates = Templates::make([
            'page.twig' => '<p>{{ title }}</p>',
            'missing.twig' => '<p>{{ never_passed }}</p>',
            'raw.twig' => '<p>{{ title|raw }}</p>',
        ]);
    }

    protected function tearDown(): void
    {
        $this->templates->remove();
    }

    private function twig(bool $debug = true, string $cacheDir = ''): \Twig\Environment
    {
        return TwigFactory::of($this->templates->dir(), $cacheDir, $debug);
    }

    public function testAutoescapingIsOnRegardlessOfTheFileName(): void
    {
        // The filename here is `page.twig`, not `page.html.twig`. Twig's own
        // default would leave this unescaped — the safety of a page must not
        // depend on an extension an author has no reason to type.
        $html = $this->twig()->render('page.twig', ['title' => '<script>alert(1)</script>']);

        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
    }

    public function testRawIsTheOptOutAndItStillWorks(): void
    {
        // Escaping on by default is only correct if the escape hatch exists and
        // is explicit — otherwise an app with legitimate HTML in a value has to
        // fight the framework.
        $html = $this->twig()->render('raw.twig', ['title' => '<b>bold</b>']);

        self::assertSame('<p><b>bold</b></p>', $html);
    }

    public function testStrictVariablesIsOn(): void
    {
        $twig = $this->twig();
        self::assertTrue($twig->isStrictVariables());

        // And the behaviour, not just the flag: a variable nobody passed fails
        // the render. With strict off this returns '<p></p>' and the page looks
        // fine, which is the defect this option exists to prevent.
        $this->expectException(RuntimeError::class);
        $twig->render('missing.twig');
    }

    public function testDebugIsPassedThroughRatherThanForced(): void
    {
        // Twig's debug mode adds dump(), which is a development tool. The pack
        // wires the app's environment state into Twig; it does not decide for
        // the app whether production should have dump().
        self::assertTrue($this->twig(debug: true)->isDebug());
        self::assertFalse($this->twig(debug: false)->isDebug());
    }

    public function testTheCacheIsOffInDebugEvenWhenADirectoryIsConfigured(): void
    {
        // The worst template bug is the one that is not there: a compiled
        // template is not recompiled when its source changes unless Twig's
        // staleness check runs, and that check is what debug:false disables. An
        // app that configured a cache directory still gets live templates in
        // dev, because that is the trade debug is for.
        self::assertFalse($this->twig(debug: true, cacheDir: $this->templates->dir() . '/cache')->getCache());
    }

    public function testTheCacheIsHonouredWhenNotInDebug(): void
    {
        $cache = $this->templates->dir() . '/cache';

        self::assertSame($cache, $this->twig(debug: false, cacheDir: $cache)->getCache());
    }

    public function testAnEmptyCacheDirectoryMeansNoCacheRatherThanTheCurrentDirectory(): void
    {
        // `''` is a deliberate "compile every render" — the right answer for a
        // read-only filesystem. Passed straight to Twig it would mean the same
        // thing, but only by accident of Twig's own reading of a falsy value;
        // asserting it keeps the pack's intent from depending on that.
        self::assertFalse($this->twig(debug: false, cacheDir: '')->getCache());
    }
}
