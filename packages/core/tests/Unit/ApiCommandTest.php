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

    /**
     * A value object's whole API is its properties, and the index used to be
     * blind to them: `AppContext` is four promoted properties and no methods, so
     * it answered "(none)" and a reader guessed a name and broke their boot
     * (Lava Notes round 4, R4-B3).
     */
    public function testAValueObjectAnswersWithItsPropertiesAndItsConstructor(): void
    {
        $data = self::console()->json('api', 'AppContext')->data();

        self::assertSame('class', $data['mode']);
        $context = $data['symbols'][0];
        self::assertSame(\Lava\Core\Boot\AppContext::class, $context['name']);
        self::assertSame([], $context['methods'], 'it has none — which is exactly why properties had to be indexed');

        $properties = array_column($context['properties'], null, 'name');
        self::assertSame(['appDir', 'env', 'config', 'features'], array_keys($properties));
        self::assertSame('string', $properties['appDir']['type']);
        self::assertTrue($properties['appDir']['readonly']);
        self::assertSame(\Lava\Core\Config\Config::class, $properties['config']['type']);

        self::assertIsString($context['constructor']);
        self::assertStringContainsString('string $appDir', $context['constructor']);
        self::assertFalse($context['abstract']);

        // And the text view shows them, rather than a bare "(none)".
        $text = self::console()->run('api', 'AppContext')->output();
        self::assertStringContainsString('readonly string $appDir', $text);
        self::assertStringContainsString('construct: new AppContext(', $text);
    }

    public function testAPropertyNameResolvesLikeAMethodName(): void
    {
        // `--search=routeName` and `api routeName` both answered 0 matches, for
        // the property every middleware that dispatches on a route needs.
        $data = self::console()->json('api', 'routeName')->data();

        self::assertSame('property', $data['mode']);
        self::assertSame(\Lava\Core\Routing\RouteArgs::class, $data['symbols'][0]['name']);
        self::assertSame(['routeName'], array_column($data['symbols'][0]['properties'], 'name'));
        self::assertSame([], $data['symbols'][0]['methods'], 'a hit is about the property, not the class\'s other API');

        $qualified = self::console()->json('api', 'RouteArgs::$routeName')->data();
        self::assertSame('property', $qualified['mode']);
        self::assertSame(['routeName'], array_column($qualified['symbols'][0]['properties'], 'name'));
    }

    /**
     * An abstract class has to read as one — `LavaProblem` looked like a class
     * you receive, so an app that wanted a problem code of its own could not see
     * the constructor or the method it must write (round 4, R4-G9).
     */
    public function testAnAbstractClassIsMarkedAndCarriesWhatASubclassNeeds(): void
    {
        $data = self::console()->json('api', 'LavaProblem')->data();

        $problem = $data['symbols'][0];
        self::assertTrue($problem['abstract']);
        self::assertStringContainsString('string $fix', (string) $problem['constructor']);
        $methods = array_column($problem['methods'], null, 'name');
        self::assertArrayHasKey('code', $methods);
        self::assertTrue($methods['code']['abstract']);

        self::assertStringContainsString('(abstract class, core)', self::console()->run('api', 'LavaProblem')->output());
    }

    public function testTheClassTheGeneratedReferenceTellsAnAppToExtendIsFindable(): void
    {
        // AGENTS.md says to extend AppCommand; `lava api` said the framework had
        // no such thing, because its whole directory is `lava list`'s business.
        $data = self::console()->json('api', 'AppCommand')->data();

        self::assertSame('class', $data['mode']);
        self::assertSame(\Lava\Core\Console\Commands\AppCommand::class, $data['symbols'][0]['name']);
        $signatures = array_column($data['symbols'][0]['methods'], 'signature', 'name');
        self::assertStringStartsWith('abstract protected inspect(', (string) ($signatures['inspect'] ?? ''));
    }

    /**
     * A count of prose hits is not a count of things that exist.
     *
     * "1 match for session" was the words "mid-session" in a test helper's
     * docblock — fine to read, wrong to branch on.
     */
    public function testASearchSaysWhetherItMatchedANameOrJustProse(): void
    {
        $byName = self::console()->json('api', '--search=rollout')->data();
        self::assertSame(['name'], array_values(array_unique(array_column($byName['symbols'], 'matched'))));

        $byProse = self::console()->json('api', '--search=mid-session')->data();
        self::assertGreaterThan(0, $byProse['total']);
        self::assertSame(['prose'], array_values(array_unique(array_column($byProse['symbols'], 'matched'))));
        self::assertStringContainsString('prose', self::console()->run('api', '--search=mid-session')->output());

        // Present in every mode, so a consumer never has to test for the key.
        self::assertNull(self::console()->json('api', 'Router')->data()['symbols'][0]['matched']);
    }

    public function testASearchResultCanBeCopiedStraightIntoAUseStatement(): void
    {
        // A short name costs a second command: the round-4 build's first test run
        // died on a class whose namespace it had to guess.
        $text = self::console()->run('api', '--search=rollout')->output();

        self::assertStringContainsString('Lava\Core\Features\Flag::rollout', $text);
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
