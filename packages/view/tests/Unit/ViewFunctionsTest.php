<?php

declare(strict_types=1);

namespace Lava\View\Tests\Unit;

use Lava\Core\Features\FeatureScope;
use Lava\Core\Features\Features;
use Lava\Core\Features\Flag;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\UnknownFeature;
use Lava\Core\Routing\Router;
use Lava\Core\Routing\UrlGenerator;
use Lava\View\Tests\Support\Flags;
use Lava\View\ViewFunctions;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

/**
 * The two template functions, called the way a template calls them.
 *
 * Called through `getCallable()` rather than through a render: the argument
 * checking IS the unit under test, and going through Twig would add a compiled
 * template, a filesystem, and a RuntimeError wrapper between the call and the
 * assertion without making the assertion stronger. What rendering adds — that
 * a problem raised here survives Twig's wrapping — is asserted over real HTTP
 * in {@see \Lava\View\Tests\Http\ViewOverHttpTest}, where it actually matters.
 */
final class ViewFunctionsTest extends TestCase
{
    private UrlGenerator $url;
    private Features $features;

    protected function setUp(): void
    {
        $router = new Router();
        $router->get('/users/{id:int}', 'users.show')->handler('not-a-real-handler');
        $router->finalize(new ProblemReport());

        $this->url = new UrlGenerator($router);
        $this->features = Flags::of(['beta_banner' => Flag::on(), 'dark_mode' => Flag::off()]);
    }

    private function callable(string $name): callable
    {
        foreach (ViewFunctions::registry($this->url, new FeatureScope($this->features)) as $function) {
            if ($function->getName() === $name) {
                /** @var callable $callable */
                $callable = $function->getCallable();

                return $callable;
            }
        }

        self::fail("no template function named '{$name}' is registered");
    }

    public function testFeatureAnswersFromTheScopeAtCallTimeNotAtRegistration(): void
    {
        // The registry is built once, with the renderer. If `feature()` held the
        // resolver it was registered with, an audience flag would answer for
        // nobody on every request; it has to ask the scope each time it is
        // called. Two resolvers with different settings stand in for "boot" and
        // "bound to this request's subject".
        $boot = Flags::of(['beta_banner' => Flag::off()]);
        $bound = Flags::of(['beta_banner' => Flag::on()]);
        $scope = new FeatureScope($boot);

        $feature = null;
        foreach (ViewFunctions::registry($this->url, $scope) as $function) {
            if ($function->getName() === 'feature') {
                $feature = $function->getCallable();
            }
        }
        self::assertIsCallable($feature);

        self::assertFalse($feature('beta_banner'));
        self::assertTrue($scope->during($bound, static fn (): bool => $feature('beta_banner')));
        self::assertFalse($feature('beta_banner'), 'the scope was restored, so the answer is boot\'s again');
    }

    /** @return array<string, mixed> */
    private static function problemFrom(callable $call, mixed ...$args): array
    {
        try {
            $call(...$args);
        } catch (LavaProblem $problem) {
            return $problem->json();
        }

        self::fail('the call was expected to fail with a LavaProblem');
    }

    public function testTheRegistryIsExactlyTwoFunctionsInOrder(): void
    {
        // The pack's promise is that the whole template namespace is one array
        // in one file. A third function arriving from anywhere — a bundle, an
        // extension, a stray addFunction() — is the thing this test exists to
        // notice, so it asserts the names and their order rather than a count.
        $names = array_map(
            static fn (TwigFunction $f): string => $f->getName(),
            ViewFunctions::registry($this->url, new FeatureScope($this->features)),
        );

        self::assertSame(['url', 'feature'], $names);
    }

    public function testUrlReturnsThePathTheRouterWouldMatch(): void
    {
        self::assertSame('/users/7', ($this->callable('url'))('users.show', ['id' => 7]));
    }

    public function testAnIntParamIsAccepted(): void
    {
        // Twig hands integers through untouched, and a route param typed `int`
        // is the case an app writes most. Rejecting it would make the function
        // useless.
        self::assertSame('/users/42', ($this->callable('url'))('users.show', ['id' => 42]));
    }

    public function testUrlDefaultsToNoParams(): void
    {
        // `{{ url('home') }}` is the common case for a route with no params,
        // and it has to be a legal call rather than a missing-argument error.
        $router = new Router();
        $router->get('/about', 'about')->handler('not-a-real-handler');
        $router->finalize(new ProblemReport());

        $call = null;
        foreach (ViewFunctions::registry(new UrlGenerator($router), new FeatureScope($this->features)) as $function) {
            if ($function->getName() === 'url') {
                /** @var callable $call */
                $call = $function->getCallable();
            }
        }
        self::assertNotNull($call);

        self::assertSame('/about', $call('about'));
    }

    public function testANonStringRouteNameIsAProblemNotATypeError(): void
    {
        $problem = self::problemFrom($this->callable('url'), 7, []);

        self::assertSame('bad_view_call', $problem['code']);
        self::assertStringContainsString('a int', (string) $problem['problem']);
        // The fix names the thing the reader is looking at: a template, and the
        // file that holds the real list of names.
        self::assertStringContainsString('app/Routes.php', (string) $problem['fix']);
    }

    public function testParamsThatAreNotAMapAreAProblem(): void
    {
        // `{{ url('users.show', 'x') }}` — a string where a hash belongs.
        $problem = self::problemFrom($this->callable('url'), 'users.show', 'x');

        self::assertSame('bad_view_call', $problem['code']);
        self::assertStringContainsString('not a map', (string) $problem['problem']);
        self::assertSame('users.show', $problem['context']['route']);
    }

    public function testAListWhereAMapBelongsIsAProblem(): void
    {
        // `{{ url('users.show', [7]) }}` — Twig hashes and sequences look the
        // same in a diff and behave differently here, which is why the key type
        // is checked and not just the value type.
        $problem = self::problemFrom($this->callable('url'), 'users.show', [7]);

        self::assertSame('bad_view_call', $problem['code']);
        self::assertStringContainsString('not a map', (string) $problem['problem']);
    }

    public function testAnObjectParamIsReportedWithItsTypeNotItsContents(): void
    {
        // The mistake this whole class exists for: `{{ url('users.show', {id:
        // user}) }}`. The message says what the value IS; it never prints the
        // value, because this string ends up in an error page and a log line.
        $problem = self::problemFrom($this->callable('url'), 'users.show', ['id' => new \stdClass()]);

        self::assertSame('bad_view_call', $problem['code']);
        self::assertStringContainsString('a stdClass', (string) $problem['problem']);
        self::assertSame('stdClass', $problem['context']['given']);
        self::assertStringContainsString('item.id', (string) $problem['fix']);
    }

    public function testANullParamGetsTheGuardTheLinkFix(): void
    {
        $problem = self::problemFrom($this->callable('url'), 'users.show', ['id' => null]);

        self::assertSame('bad_view_call', $problem['code']);
        self::assertStringContainsString('null', (string) $problem['problem']);
        self::assertStringContainsString('is not null', (string) $problem['fix']);
    }

    public function testNoValueEverReachesTheProblem(): void
    {
        // The non-disclosure rule lava/validate follows for a submitted value,
        // applied here to a template's context: a route param can be anything
        // an app put in its model, and a problem message is a page and a log
        // line. The type is what the fix is about; the value is never needed.
        $secret = 'correct-horse-battery-staple';
        $problem = self::problemFrom($this->callable('url'), 'users.show', ['id' => ['token' => $secret]]);

        $rendered = json_encode($problem, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $rendered);
        self::assertStringNotContainsString('token', $rendered);
    }

    public function testFeatureAnswersFromTheSameResolverTheRouterUses(): void
    {
        self::assertTrue(($this->callable('feature'))('beta_banner'));
        self::assertFalse(($this->callable('feature'))('dark_mode'));
    }

    public function testANonStringFlagNameIsAProblem(): void
    {
        $problem = self::problemFrom($this->callable('feature'), true);

        self::assertSame('bad_view_call', $problem['code']);
        self::assertStringContainsString('a bool', (string) $problem['problem']);
        self::assertStringContainsString('config/features.php', (string) $problem['fix']);
    }

    public function testAnUnknownFlagNameIsLeftToCore(): void
    {
        // Deliberately NOT caught and re-coded. `feature()` is a thin pass
        // through to the resolver, and the resolver already answers an unknown
        // name with the nearest real one — wrapping it in a bad_view_call would
        // replace a message that suggests a spelling with one that does not.
        $this->expectException(UnknownFeature::class);
        ($this->callable('feature'))('beta_bannner');
    }
}
