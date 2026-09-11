<?php

declare(strict_types=1);

namespace Lava\View\Tests\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\View\Problem\BadViewCall;
use Lava\View\Problem\TemplateFailed;
use Lava\View\Problem\TemplateNotFound;
use Lava\View\Problem\ViewDirMissing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Source;

/**
 * The pack's four codes, and the shape every one of them has to keep.
 *
 * `docs/problem-codes.md` states the rule this file enforces: every code maps
 * 1:1 to one Problem class and is exercised by a fixture test. The 1:1 half is
 * the table below — a code that appears twice, or a class that returns a code
 * nobody registered, is a contract break, and the way to notice it is to assert
 * the mapping rather than to read it.
 *
 * The shape half is `json()`'s six keys in order. That object is what an agent
 * matches on across boot reports, `lava check`, HTTP error pages and every
 * `--json` command, so a field appearing or moving is a schema change — the
 * kind that breaks a consumer silently, in a place this repository cannot see.
 */
final class ViewProblemsTest extends TestCase
{
    /** @return iterable<string, array{LavaProblem, string}> */
    public static function codes(): iterable
    {
        $source = new Source('<p>', 'broken.twig', '/app/views/broken.twig');

        yield 'template_not_found' => [
            TemplateNotFound::of('page.twig', '/app/views', ['page.twig']),
            'template_not_found',
        ];
        yield 'template_failed (syntax)' => [
            TemplateFailed::syntax('broken.twig', new SyntaxError('Unexpected end of template.', 3, $source)),
            'template_failed',
        ];
        yield 'template_failed (runtime)' => [
            TemplateFailed::runtime('untyped.twig', new RuntimeError('Variable "never_passed" does not exist.', 2, $source)),
            'template_failed',
        ];
        yield 'view_dir_missing' => [
            ViewDirMissing::of('/app/views', 'view.path', '/app'),
            'view_dir_missing',
        ];
        yield 'bad_view_call (route name)' => [
            BadViewCall::routeName(null),
            'bad_view_call',
        ];
        yield 'bad_view_call (params)' => [
            BadViewCall::urlParams('users.show', 'x'),
            'bad_view_call',
        ];
        yield 'bad_view_call (param value)' => [
            BadViewCall::urlParam('users.show', 'id', null),
            'bad_view_call',
        ];
        yield 'bad_view_call (feature name)' => [
            BadViewCall::featureName(true),
            'bad_view_call',
        ];
    }

    #[DataProvider('codes')]
    public function testEveryProblemHasItsRegisteredCodeAndTheStableShape(LavaProblem $problem, string $code): void
    {
        self::assertSame($code, $problem->code());

        // Field order is part of the contract, not an accident of how the
        // array was written — so the order is asserted, not just the presence.
        self::assertSame(
            ['code', 'problem', 'fix', 'context', 'source', 'severity'],
            array_keys($problem->json()),
        );

        // Every one of these is a developer fault, so every one is Fatal and a
        // 500. A template mistake is not the caller's — the caller asked for a
        // page and the page is broken.
        self::assertSame('fatal', $problem->severity()->value);
        self::assertSame(500, $problem->httpStatus());

        // A fix is imperative and non-empty: it is the half of the problem an
        // agent acts on, and a problem without one is a stack trace with extra
        // steps.
        self::assertNotSame('', $problem->fix);
    }

    public function testTheFourCodesAreDistinct(): void
    {
        // The registry rule, from the other side: four classes, four codes. A
        // copy-paste that left two classes returning the same string would be
        // invisible in the table above and would break every consumer matching
        // on it. Sorted, so the assertion is about the set and not about the
        // order the provider happens to yield.
        $codes = [];
        foreach (self::codes() as [$problem]) {
            $codes[$problem->code()] = true;
        }
        $names = array_keys($codes);
        sort($names);

        self::assertSame(
            ['bad_view_call', 'template_failed', 'template_not_found', 'view_dir_missing'],
            $names,
        );
    }

    public function testSyntaxAndRuntimeFailuresShareACodeAndDifferInTheFix(): void
    {
        $source = new Source('<p>', 'broken.twig', '/app/views/broken.twig');

        $syntax = TemplateFailed::syntax('broken.twig', new SyntaxError('Unexpected end of template.', 3, $source));
        $runtime = TemplateFailed::runtime('broken.twig', new RuntimeError('Variable "title" does not exist.', 2, $source));

        self::assertSame($syntax->code(), $runtime->code());

        // Different files to open: the template for a typo, the handler for a
        // context that was never passed. That difference is the entire reason
        // there are two factories.
        self::assertStringContainsString('Twig reports the first error only', $syntax->fix);
        self::assertStringContainsString('render() in the handler', $runtime->fix);
        self::assertStringNotContainsString('render() in the handler', $syntax->fix);
    }

    public function testTheSourceIsTheTemplateFileAndLineTwigAlreadyComputed(): void
    {
        // Twig knows both. Keeping them is the whole job of this wrapper: an
        // uncaught SyntaxError becomes `unexpected_failure`, which throws away
        // a line the framework already had.
        $source = new Source('<p>', 'broken.twig', '/app/views/broken.twig');
        $problem = TemplateFailed::syntax('broken.twig', new SyntaxError('Unexpected end of template.', 3, $source));

        self::assertNotNull($problem->source);
        self::assertSame('/app/views/broken.twig', $problem->source->file);
        self::assertSame(3, $problem->source->line);
        // `file:line`, openable as printed — the whole reason the location is
        // carried rather than described.
        self::assertSame('/app/views/broken.twig:3', (string) $problem->source);
    }

    public function testWithoutASourceContextTheFallbackIsTheTemplateAsGiven(): void
    {
        // A runtime error raised from a function has no source context. The
        // fallback is the name the renderer passed, which is already normalized
        // — appending `.twig` here would produce `broken.twig.twig` and a file
        // path that does not exist.
        $problem = TemplateFailed::runtime('broken.twig', new RuntimeError('boom'));

        self::assertNotNull($problem->source);
        self::assertSame('broken.twig', $problem->source->file);
        // Twig reports -1 for "no line"; 1 is the closest honest answer.
        self::assertSame(1, $problem->source->line);
    }

    public function testTheTemplateCallProblemsCarryNoSource(): void
    {
        // `source` is the user-authored artifact at fault, and for these it is
        // the template's call site — a line inside text Twig compiled into
        // `var/views/`, which no longer resembles what was written. Pointing at
        // it would send the reader to a generated file, so these name the fix
        // instead and leave `source` null rather than pointing somewhere wrong.
        self::assertNull(BadViewCall::routeName(7)->source);
        self::assertNull(BadViewCall::urlParam('users.show', 'id', null)->source);
        self::assertNull(BadViewCall::featureName(true)->source);

        // `view_dir_missing` is the other case: the boot step knows the app
        // directory, so it can say where the app is even though there is no
        // line to point at. It is carried in `context`, not `source`.
        self::assertNull(ViewDirMissing::of('/app/views', 'view.path', '/app')->source);
        self::assertSame('/app', ViewDirMissing::of('/app/views', 'view.path', '/app')->context['app_dir']);
    }

    public function testTheDirectoryProblemNamesThePathTheKeyAndTheApp(): void
    {
        // The fix has to name the config key or the reader knows the directory
        // is wrong and not where the directory is set.
        $problem = ViewDirMissing::of('/app/views', 'view.path', '/app');

        self::assertStringContainsString('/app/views', $problem->getMessage());
        self::assertStringContainsString('config/view.php', $problem->fix);
        self::assertStringContainsString('/app', $problem->fix);
        self::assertSame('/app/views', $problem->context['path']);
        self::assertSame('view.path', $problem->context['config_key']);
    }
}
