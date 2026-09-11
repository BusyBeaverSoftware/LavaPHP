<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Modules\ModuleRef;
use Lava\Core\Problem\DuplicateService;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\MissingPack;
use Lava\Core\Problem\ProblemCliRenderer;
use Lava\Core\Problem\ProblemJsonRenderer;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\ServiceNotRegistered;
use Lava\Core\Problem\Severity;
use Lava\Core\Problem\SourceLocation;
use Lava\Core\Problem\UnknownFeature;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

/**
 * The problem contract everything else relies on: stable JSON shape, an
 * imperative fix on every problem, and a report that collects instead of
 * failing fast.
 */
final class ProblemSystemTest extends TestCase
{
    public function testProblemJsonHasTheStableShape(): void
    {
        $problem = self::missingSearch();

        self::assertSame(
            ['code', 'problem', 'fix', 'context', 'source', 'severity'],
            array_keys($problem->json()),
        );
        self::assertSame('missing_pack', $problem->json()['code']);
        self::assertSame('fatal', $problem->json()['severity']);
        self::assertSame(['file' => __FILE__, 'line' => $problem->source->line], $problem->json()['source']);
    }

    public function testMissingPackFixNamesTheExactInstallCommand(): void
    {
        $problem = self::missingSearch();

        self::assertStringContainsString('composer require lava/search', $problem->fix);
        self::assertSame('search', $problem->context['feature']);
        self::assertSame('lava/search', $problem->context['package']);
    }

    public function testServiceNotRegisteredIsAPsr11NotFound(): void
    {
        $problem = ServiceNotRegistered::of(\App\Thing::class);

        self::assertInstanceOf(NotFoundExceptionInterface::class, $problem);
        self::assertSame('service_not_registered', $problem->code());
        self::assertStringContainsString('app/Services.php', $problem->fix);
    }

    public function testUnknownFeatureCarriesTheNearestNameFix(): void
    {
        $problem = UnknownFeature::of('beta_gretting', 'beta_greeting');

        self::assertSame('unknown_feature', $problem->code());
        self::assertSame("Did you mean 'beta_greeting'? It is defined in config/features.php.", $problem->fix);
    }

    public function testReportCollectsAllProblemsInDiscoveryOrder(): void
    {
        $report = new ProblemReport();
        $report->add(UnknownFeature::of('a', null));
        $report->add(self::warnProblem());
        $report->add(DuplicateService::of('x', SourceLocation::of('a.php', 1), SourceLocation::of('b.php', 2)));

        self::assertCount(3, $report->problems());
        self::assertTrue($report->hasFatals());
        self::assertCount(2, $report->fatals());
        self::assertCount(1, $report->warns());

        $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $report->problems());
        self::assertSame(['unknown_feature', 'test_warn', 'duplicate_service'], $codes);
    }

    public function testCliRendererIsGrepFriendlyAndAlwaysShowsTheFix(): void
    {
        $report = new ProblemReport();
        $report->add(self::missingSearch());

        $text = (new ProblemCliRenderer())->render($report);

        self::assertStringContainsString('[X] [fatal] missing_pack', $text);
        self::assertStringContainsString("PROBLEM: Feature 'search' is enabled", $text);
        self::assertStringContainsString('FIX: Run: composer require lava/search', $text);
        self::assertStringContainsString('AT: ' . __FILE__ . ':', $text);
        self::assertStringContainsString('feature: search', $text);
    }

    public function testCliRendererOnAnEmptyReport(): void
    {
        self::assertSame("LavaPHP: no problems.\n", (new ProblemCliRenderer())->render(new ProblemReport()));
    }

    public function testJsonRendererEmitsExactlyTheReportJson(): void
    {
        $report = new ProblemReport();
        $report->add(UnknownFeature::of('a', null));
        $report->add(self::warnProblem());

        self::assertSame($report->json(), json_decode((new ProblemJsonRenderer())->render($report), true));
    }

    private static function missingSearch(): MissingPack
    {
        // A fictional pack on purpose: every real pack in this monorepo is
        // installed, so a MissingPack built from one would describe a state the
        // framework can no longer produce. See missing-pack-app/app/Modules.php.
        return MissingPack::of(ModuleRef::of(\Lava\Search\SearchModule::class, package: 'lava/search', feature: 'search'));
    }

    private static function warnProblem(): LavaProblem
    {
        return new class () extends LavaProblem {
            public function __construct()
            {
                parent::__construct('A deliberate warn for the report test.', 'Ignore — test fixture.');
            }

            public function code(): string
            {
                return 'test_warn';
            }

            public function severity(): Severity
            {
                return Severity::Warn;
            }
        };
    }
}