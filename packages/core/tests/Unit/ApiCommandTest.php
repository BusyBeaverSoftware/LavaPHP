<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\ExitCode;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestConsole;
use PHPUnit\Framework\TestCase;

/**
 * `lava api` as an agent actually asks it.
 *
 * The modes exist because the question arrives in different shapes — a method
 * name, a class name, a word — and the one that matters most is the last test
 * here: a query that matches nothing must come back empty and say so, because
 * over a closed surface that is an answer ("the framework has no such thing")
 * rather than a failed search.
 */
final class ApiCommandTest extends TestCase
{
    private static function console(): TestConsole
    {
        return new TestConsole(TestApp::fixturePath('ok-app'));
    }

    public function testWithNoArgumentsItListsThePacksRatherThanSixHundredRows(): void
    {
        $result = self::console()->json('api');

        self::assertSame(ExitCode::Ok, $result->exitCode(), $result->errors());
        $data = $result->data();
        self::assertSame('roster', $data['mode']);
        self::assertNull($data['query']);
        self::assertSame([], $data['symbols'], 'the roster answers "what is there", not "here is everything"');
        self::assertGreaterThan(100, $data['total'], 'total still reports the size of the whole index');

        $packs = array_column($data['packs'], null, 'pack');
        self::assertArrayHasKey('core', $packs);
        self::assertSame('lavaphp/core', $packs['core']['package']);
        self::assertNull($packs['core']['feature'], 'core is never gated');
        self::assertGreaterThan(0, $packs['core']['methods']);

        // A pack that is installed but switched off is still listed: not knowing
        // a capability exists is the failure this command exists to prevent.
        self::assertArrayHasKey('db', $packs);
        self::assertSame('db', $packs['db']['feature']);
    }

    public function testAClassNameAnswersWithThatClassInFull(): void
    {
        $data = self::console()->json('api', 'Router')->data();

        self::assertSame('class', $data['mode']);
        self::assertCount(1, $data['symbols']);
        $router = $data['symbols'][0];
        self::assertSame(\Lava\Core\Routing\Router::class, $router['name']);
        self::assertSame('core', $router['pack']);
        self::assertStringStartsWith('core:src/Routing/Router.php', $router['at']);
        self::assertNotNull($router['example'], 'an entry point carries a worked example');

        $methods = array_column($router['methods'], 'signature', 'name');
        self::assertArrayHasKey('get', $methods);
        self::assertStringContainsString('$path', $methods['get']);
        self::assertArrayNotHasKey('finalize', $methods, 'a boot internal is marked @internal and stays out');
    }

    public function testAMethodNameAnswersWithEveryClassThatHasOne(): void
    {
        $data = self::console()->json('api', 'rollout')->data();

        self::assertSame('method', $data['mode']);
        self::assertSame(\Lava\Core\Features\Flag::class, $data['symbols'][0]['name']);
        // Cut down to the match: a hit is about the method, so the class's other
        // methods would bury it.
        self::assertSame(['rollout'], array_column($data['symbols'][0]['methods'], 'name'));
        self::assertTrue($data['symbols'][0]['methods'][0]['static']);
    }

    public function testAQualifiedNameResolvesToTheOneMethod(): void
    {
        $data = self::console()->json('api', 'Responses::json')->data();

        self::assertSame('method', $data['mode']);
        self::assertSame(['json'], array_column($data['symbols'][0]['methods'], 'name'));
        self::assertSame(\Lava\Core\Http\Responses::class, $data['symbols'][0]['name']);
    }

    public function testSearchLooksThroughNamesAndSummaries(): void
    {
        $data = self::console()->json('api', '--search=redirect')->data();

        self::assertSame('search', $data['mode']);
        self::assertGreaterThan(0, $data['total']);
        $names = array_column($data['symbols'], 'name');
        self::assertContains(\Lava\Core\Routing\Router::class, $names, 'Router::redirect() should surface');
    }

    public function testAScopedRequestAnswersForOnePackOnly(): void
    {
        $data = self::console()->json('api', '--pack=events')->data();

        self::assertSame('pack', $data['mode']);
        self::assertNotSame([], $data['symbols']);
        self::assertSame(['events'], array_values(array_unique(array_column($data['symbols'], 'pack'))));
    }

    public function testEverythingAtOnceCarriesEveryPack(): void
    {
        $data = self::console()->json('api', '--all')->data();

        self::assertSame('all', $data['mode']);
        $packs = array_unique(array_column($data['symbols'], 'pack'));
        self::assertContains('core', $packs);
        self::assertContains('db', $packs);
        self::assertSame(count($data['symbols']), $data['total']);
        self::assertFalse($data['truncated']);
    }

    public function testABroadSearchIsCappedAndSaysSo(): void
    {
        $data = self::console()->json('api', '--search=the')->data();

        self::assertGreaterThan(50, $data['total'], 'this term is meant to over-match');
        self::assertCount(50, $data['symbols']);
        self::assertTrue($data['truncated'], 'a consumer must be able to tell it did not get everything');
    }

    /**
     * The whole point of a closed surface: nothing found is an ANSWER.
     *
     * An agent that searched, found nothing and concluded the framework had
     * nothing is what produced a 690-line workaround for a capability that
     * shipped. Here, empty means empty.
     */
    public function testAQueryThatMatchesNothingAnswersEmptyRatherThanFailing(): void
    {
        $result = self::console()->json('api', 'sendCarrierPigeon');

        self::assertSame(ExitCode::Ok, $result->exitCode(), 'not finding something is not an error');
        $data = $result->data();
        self::assertSame('search', $data['mode']);
        self::assertSame(0, $data['total']);
        self::assertSame([], $data['symbols']);
        self::assertFalse($data['truncated']);
        self::assertNotSame([], $data['packs'], 'the roster still says what was searched');
    }

    public function testTheTextViewLeadsWithWhatAReaderScans(): void
    {
        $roster = self::console()->run('api');
        self::assertStringContainsString('lavaphp/core', $roster->output());
        self::assertStringContainsString('lava api <ClassName>', $roster->output());

        $class = self::console()->run('api', 'Flag');
        self::assertStringContainsString('Lava\Core\Features\Flag', $class->output());
        self::assertStringContainsString('static rollout(int $percentage)', $class->output());
        self::assertStringContainsString('example:', $class->output());
    }

    public function testAnUndeclaredFlagIsRefusedWithTheKeysItsSchemaPromises(): void
    {
        $result = self::console()->json('api', '--verbose');

        self::assertSame(ExitCode::Usage, $result->exitCode());
        // The roster needs no app, so even a rejected invocation carries it.
        self::assertSame(
            ['query', 'mode', 'total', 'truncated', 'packs', 'symbols'],
            array_keys($result->data()),
        );
    }
}
