<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Features\Feature;
use Lava\Core\Features\FeatureSet;
use Lava\Core\Features\FeatureSettings;
use Lava\Core\Features\Features;
use Lava\Core\Features\Flag;
use Lava\Core\Problem\BadRoutePattern;
use Lava\Core\Problem\DuplicateRouteName;
use Lava\Core\Problem\MethodNotAllowed;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\RouteNotFound;
use Lava\Core\Problem\UnknownFeature;
use Lava\Core\Problem\UnknownRoute;
use Lava\Core\Routing\Matched;
use Lava\Core\Routing\Method;
use Lava\Core\Routing\RouteArgs;
use Lava\Core\Routing\Router;
use Lava\Core\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The router in isolation: compile, match, gating, URL generation. Handlers
 * here are inert strings — match() never invokes them (validation of real
 * handlers is HandlerInvoker's job, tested in HandlerInvokerTest).
 */
final class RoutingTest extends TestCase
{
    private const UUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    /** A finalized router with one route per param type. */
    private function router(): Router
    {
        $r = new Router();
        $r->pattern('word', '[a-z]+');
        $r->get('/users/{id:int}', 'users.show')->handler(['MatcherOnly', 'show']);
        $r->add('/health', 'health', Method::Get, Method::Head)->handler('matcher_only_function');
        $r->get('/greet/{name:word}', 'greet')->handler('matcher_only_function');
        $r->get('/files/{rest:path}', 'files')->handler('matcher_only_function');
        $r->get('/items/{token:uuid}', 'items.by_token')->handler('matcher_only_function');
        $r->get('/beta/dashboard', 'beta.dashboard')->handler('matcher_only_function')->when('beta_ui');
        $r->finalize(new ProblemReport());
        return $r;
    }

    private function featuresWith(string $name, Flag $flag): Features
    {
        $definitions = new FeatureSet();
        $definitions->add(Feature::define($name, $flag), __FILE__);
        return new Features($definitions, new FeatureSettings(), null, 'dev');
    }

    public function testMatchReturnsTypedArgs(): void
    {
        $result = $this->router()->match('GET', '/users/42');

        self::assertInstanceOf(Matched::class, $result);
        self::assertSame('users.show', $result->route->name);
        self::assertSame(42, $result->args->int('id')); // int type converts, not just casts a string
        self::assertSame(['id' => 42], $result->args->all());
    }

    public function testIntParamsRejectNonDigits(): void
    {
        $router = $this->router();

        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/users/12a'));
        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/users/-1'));
        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/users/4.2'));
    }

    public function testWrongMethodIsNotAllowedAndNamesTheAcceptedOnes(): void
    {
        $result = $this->router()->match('POST', '/users/42');

        self::assertInstanceOf(MethodNotAllowed::class, $result);
        self::assertSame(['GET'], $result->context['allowed']);
        self::assertStringContainsString('This path accepts: GET', $result->fix);
    }

    public function testHeadIsNeverAutoMappedToGet(): void
    {
        $router = $this->router();

        // /users only declares GET — HEAD is a real method, not an alias.
        self::assertInstanceOf(MethodNotAllowed::class, $router->match('HEAD', '/users/42'));

        // /health declared HEAD explicitly, so it matches.
        self::assertInstanceOf(Matched::class, $router->match('HEAD', '/health'));
        self::assertInstanceOf(Matched::class, $router->match('GET', '/health'));
    }

    public function testCustomPatternTypesAreExact(): void
    {
        $router = $this->router();

        $ok = $router->match('GET', '/greet/ada');
        self::assertInstanceOf(Matched::class, $ok);
        self::assertSame('ada', $ok->args->str('name'));

        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/greet/Ada')); // case
        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/greet/123'));
        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/greet/a-b'));
    }

    public function testPathTypeSpansSlashes(): void
    {
        $result = $this->router()->match('GET', '/files/a/b/c.txt');

        self::assertInstanceOf(Matched::class, $result);
        self::assertSame('a/b/c.txt', $result->args->str('rest'));
    }

    public function testUuidType(): void
    {
        $router = $this->router();

        $ok = $router->match('GET', '/items/' . self::UUID);
        self::assertInstanceOf(Matched::class, $ok);
        self::assertSame(self::UUID, $ok->args->uuid('token'));

        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/items/nope'));
    }

    public function testGatedRoutesAreAbsentWhenOffOrUnresolvable(): void
    {
        $router = $this->router();

        // Fail-closed: no Features in hand (e.g. a degraded boot) → gated route absent.
        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/beta/dashboard'));
        // Off for this subject → absent, exactly like a route that was never registered.
        self::assertInstanceOf(RouteNotFound::class, $router->match('GET', '/beta/dashboard', $this->featuresWith('beta_ui', Flag::off())));

        // Ungated routes never consult the flags.
        self::assertInstanceOf(Matched::class, $router->match('GET', '/users/7', null));
    }

    public function testGatedRouteMatchesWhenOn(): void
    {
        $result = $this->router()->match('GET', '/beta/dashboard', $this->featuresWith('beta_ui', Flag::on()));

        self::assertInstanceOf(Matched::class, $result);
        self::assertSame([], $result->args->all());
    }

    public function testUndefinedGateFailsLoudlyAtMatchTime(): void
    {
        // BuildRouter rejects undefined gate names at boot; this asserts the
        // runtime backstop — an unknown flag is never silently false.
        $this->expectException(UnknownFeature::class);
        $this->router()->match('GET', '/beta/dashboard', $this->featuresWith('some_other_flag', Flag::on()));
    }

    public function testFinalizeCollectsAllBadRoutesAndKeepsTheGoodOnes(): void
    {
        $r = new Router();
        $r->get('/a', 'a');                                        // no handler chained
        $r->get('/b/{x}', 'b')->handler('f');                      // untyped param
        $r->get('/c', 'c')->handler('f');                          // valid
        $report = new ProblemReport();
        $r->finalize($report);

        $codes = array_map(static fn ($p): string => $p->code(), $report->problems());
        self::assertSame(['bad_handler', 'bad_route_pattern'], $codes);
        self::assertSame(['c'], $r->names());
    }

    public function testBadPathShapeFailsAtRegistration(): void
    {
        $this->expectException(BadRoutePattern::class);
        $this->expectExceptionMessage('paths must start with');
        (new Router())->get('nope', 'x');
    }

    public function testBadRouteNameFailsAtRegistration(): void
    {
        $this->expectException(BadRoutePattern::class);
        $this->expectExceptionMessage('Route name');
        (new Router())->get('/x', 'BadName');
    }

    public function testDuplicateRouteNameCarriesBothSites(): void
    {
        $r = new Router();
        $r->get('/a', 'dup')->handler('f');

        try {
            $r->get('/b', 'dup')->handler('f');
            self::fail('DuplicateRouteName was not thrown.');
        } catch (DuplicateRouteName $problem) {
            self::assertSame('duplicate_route_name', $problem->code());
            self::assertSame(__FILE__, $problem->source->file);
        }
    }

    public function testDuplicateRouteNameThroughALoaderClosurePointsAtTheUsersLine(): void
    {
        // app/Routes.php always registers from inside a closure — and a
        // closure's own frame reports its INVOKER's line, never its body's.
        // The attribution walk must reach past every Router frame to the
        // user's exact line, the same shape BuildRouter invokes.
        $r = new Router();
        $dupLine = 0;
        $loader = function (Router $r) use (&$dupLine): void {
            $r->get('/a', 'dup')->handler('f');
            $dupLine = __LINE__ + 1;
            $r->get('/b', 'dup')->handler('f');
        };

        try {
            $loader($r);
            self::fail('DuplicateRouteName was not thrown.');
        } catch (DuplicateRouteName $problem) {
            self::assertSame(__FILE__, $problem->source->file);
            self::assertSame($dupLine, $problem->source->line);
        }
    }

    public function testPatternRegistrationGuards(): void
    {
        $r = new Router();

        try {
            $r->pattern('int', '\d+');
            self::fail('Overriding a builtin type must fail.');
        } catch (BadRoutePattern $problem) {
            self::assertStringContainsString('builtin', $problem->getMessage());
        }

        $r->pattern('word', '[a-z]+');
        try {
            $r->pattern('word', '[0-9]+');
            self::fail('Re-registering a type must fail.');
        } catch (BadRoutePattern $problem) {
            self::assertStringContainsString('already defined', $problem->getMessage());
        }

        try {
            $r->pattern('UPPER', 'x');
            self::fail('Uppercase type names must fail.');
        } catch (BadRoutePattern $problem) {
            self::assertStringContainsString('snake_case', $problem->fix);
        }

        try {
            $r->pattern('frag', '(unclosed');
            self::fail('Invalid regex fragments must fail.');
        } catch (BadRoutePattern $problem) {
            self::assertStringContainsString('invalid regex', $problem->getMessage());
        }
    }

    public function testAFragmentMeansTheSameWhenCheckedMatchedAndTurnedIntoAUrl(): void
    {
        // Lava Notes R2-B2: pattern() and url() used `/…/`, match() used `#…#`.
        $r = new Router();
        $r->pattern('catpath', '[a-z]+(?:/[a-z]+)*');
        $r->pattern('tag', '[a-z#]+');
        $r->pattern('escaped', '[a-z\#]+');
        $r->get('/category/{path:catpath}', 'categories.show')->handler(['MatcherOnly', 'category']);
        $r->get('/tags/{tag:tag}', 'tags.show')->handler(['MatcherOnly', 'tag']);
        $r->get('/escaped/{tag:escaped}', 'escaped.show')->handler(['MatcherOnly', 'escaped']);
        $r->get('/people/{name:str}', 'people.show')->handler(['MatcherOnly', 'person']);
        $report = new ProblemReport();
        $r->finalize($report);
        self::assertTrue($report->isEmpty());
        $url = new UrlGenerator($r);

        $category = $r->match('GET', '/category/tech/php');
        self::assertInstanceOf(Matched::class, $category);
        self::assertSame('tech/php', $category->args->str('path'));
        self::assertSame('/category/tech/php', $url->url('categories.show', ['path' => 'tech/php']));

        foreach (['tags.show' => '/tags/c#', 'escaped.show' => '/escaped/c#'] as $name => $path) {
            self::assertInstanceOf(Matched::class, $r->match('GET', $path), $path);
            self::assertSame($path, $url->url($name, ['tag' => 'c#']));
        }

        self::assertSame('/people/ada-lovelace', $url->url('people.show', ['name' => 'ada-lovelace']), "Core's own str type, [^/]+.");
        try {
            $url->url('people.show', ['name' => 'ada/lovelace']);
            self::fail('A str param still refuses a slash.');
        } catch (BadRoutePattern $problem) {
            self::assertStringContainsString("does not match type 'str'", $problem->getMessage());
        }
    }

    public function testARouteWhoseTypesDoNotCompileTogetherIsRefusedAtFinalize(): void
    {
        $r = new Router();
        $r->pattern('named', '(?P<slug>[a-z]+)');
        $r->get('/posts/{slug:named}', 'posts.show')->handler(['MatcherOnly', 'show']);
        $r->get('/ok', 'ok')->handler(['MatcherOnly', 'ok']);
        $report = new ProblemReport();
        $r->finalize($report);

        $problems = $report->problems();
        self::assertCount(1, $problems);
        self::assertInstanceOf(BadRoutePattern::class, $problems[0]);
        self::assertStringContainsString("'/posts/{slug:named}' is invalid: its compiled pattern is not a valid regex", $problems[0]->getMessage());
        self::assertSame(['ok'], $r->names(), 'Refused once at boot, not warned about on every request.');
    }

    public function testEveryRouteProblemPointsAtTheLineThatCausedIt(): void
    {
        // Lava Notes R3-B13: only bad_redirect carried a source.
        $r = new Router();
        $r->pattern('named', '(?P<slug>[a-z]+)');
        $lines = [];
        $lines[] = __LINE__ + 1;
        $r->get('/unknown/{x:nosuchtype}', 'unknown')->handler('f');
        $lines[] = __LINE__ + 1;
        $r->get('/untyped/{x}', 'untyped')->handler('f');
        $lines[] = __LINE__ + 1;
        $r->get('/clash/{slug:named}', 'clash')->handler('f');
        $lines[] = __LINE__ + 1;
        $r->get('/nohandler', 'nohandler');
        $report = new ProblemReport();
        $r->finalize($report);

        $problems = $report->problems();
        self::assertSame(['bad_route_pattern', 'bad_route_pattern', 'bad_route_pattern', 'bad_handler'], array_map(static fn ($p): string => $p->code(), $problems));
        foreach ($problems as $i => $problem) {
            self::assertSame([__FILE__, $lines[$i]], [$problem->source?->file, $problem->source?->line], $problem->getMessage());
        }

        $refusals = [
            [__LINE__, static fn (Router $r) => $r->get('nope', 'x')],
            [__LINE__, static fn (Router $r) => $r->get('/x', 'BadName')],
            [__LINE__, static fn (Router $r) => $r->add('/x', 'x')],
            [__LINE__, static fn (Router $r) => $r->pattern('UPPER', 'x')],
            [__LINE__, static fn (Router $r) => $r->pattern('int', '\d+')],
            [__LINE__, static fn (Router $r) => $r->pattern('frag', '(unclosed')],
        ];
        foreach ($refusals as [$line, $refusal]) {
            try {
                $refusal(new Router());
                self::fail("The call on line {$line} must be refused.");
            } catch (BadRoutePattern $problem) {
                self::assertSame([__FILE__, $line], [$problem->source?->file, $problem->source?->line], $problem->getMessage());
            }
        }
    }

    public function testAGeneratedUrlIsAlwaysAPathOnThisSite(): void
    {
        // Lava Notes R3-B1: `//host` and `/\host` are other hosts to a browser.
        $r = new Router();
        $r->get('/{rest:path}', 'page')->handler('f');
        $r->get('/{name:str}/edit', 'edit')->handler('f');
        $r->finalize(new ProblemReport());
        $url = new UrlGenerator($r);

        self::assertSame('/%2Fevil.example/x', $url->url('page', ['rest' => '/evil.example/x']));
        self::assertSame('/%5Cevil.example/edit', $url->url('edit', ['name' => '\evil.example']));
        self::assertSame('/a//b', $url->url('page', ['rest' => 'a//b']), 'Only a slash or backslash right after the leading one names a host.');
    }

    public function testUrlGenerationRoundTripsEveryParamType(): void
    {
        $url = new UrlGenerator($this->router());

        self::assertSame('/users/42', $url->url('users.show', ['id' => 42]));
        self::assertSame('/greet/ada', $url->url('greet', ['name' => 'ada']));
        self::assertSame('/files/a/b.txt', $url->url('files', ['rest' => 'a/b.txt']));
        self::assertSame('/items/' . self::UUID, $url->url('items.by_token', ['token' => self::UUID]));
    }

    public function testUrlGenerationValidatesEverything(): void
    {
        $url = new UrlGenerator($this->router());

        try {
            $url->url('users.show');
            self::fail('Missing param must fail.');
        } catch (BadRoutePattern $problem) {
            self::assertStringContainsString("['id' =>", $problem->fix);
        }

        try {
            $url->url('users.show', ['id' => 'abc']);
            self::fail('Value that would not match must fail.');
        } catch (BadRoutePattern $problem) {
            self::assertStringContainsString("does not match type 'int'", $problem->getMessage());
        }

        try {
            $url->url('users.show', ['id' => 42, 'x' => 1]);
            self::fail('Extra params must fail.');
        } catch (BadRoutePattern $problem) {
            self::assertStringContainsString("has no param 'x'", $problem->getMessage());
        }

        try {
            $url->url('users.shwo', ['id' => 1]);
            self::fail('Unknown route must fail.');
        } catch (UnknownRoute $problem) {
            self::assertStringContainsString("Did you mean 'users.show'", $problem->fix);
        }
    }

    public function testRouteArgsAccessorsAndErrors(): void
    {
        $args = new RouteArgs('users.show', ['id' => 42, 'slug' => 'ada']);

        self::assertTrue($args->has('id'));
        self::assertFalse($args->has('ghost'));
        self::assertSame(42, $args->int('id'));
        self::assertSame('42', $args->str('id')); // int coerces to string on request
        self::assertSame('ada', $args->str('slug'));
        self::assertSame('ada', $args->uuid('slug')); // uuid() is a pass-through; the regex did the validating
        self::assertSame(['id' => 42, 'slug' => 'ada'], $args->all());

        try {
            $args->int('ghost');
            self::fail('Unknown param must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Params: id, slug", $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not an integer');
        $args->int('slug');
    }
}