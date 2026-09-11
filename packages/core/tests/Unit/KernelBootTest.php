<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Boot\Kernel;
use Lava\Core\Boot\Steps\BuildFeatures;
use Lava\Core\Boot\Steps\BuildRouter;
use Lava\Core\Boot\Steps\CheckModules;
use Lava\Core\Boot\Steps\CollectFlagDefinitions;
use Lava\Core\Boot\Steps\LoadConfig;
use Lava\Core\Boot\Steps\LoadDotEnv;
use Lava\Core\Boot\Steps\RegisterCoreServices;
use Lava\Core\Boot\Steps\WireAppServices;
use Lava\Core\Boot\Steps\WireModules;
use Lava\Core\Features\FlagSource;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Routing\HandlerPlan;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real kernel against the fixture apps. The fixtures double as
 * executable specs of the diagnostics: every failure mode here is one an
 * agent will hit and must be able to self-correct from the report alone.
 */
final class KernelBootTest extends TestCase
{
    public function testStepsIsTheWholeBootAsOneReadableList(): void
    {
        self::assertSame([
            LoadDotEnv::class,
            LoadConfig::class,
            CollectFlagDefinitions::class,
            BuildFeatures::class,
            CheckModules::class,
            RegisterCoreServices::class,
            WireModules::class,
            WireAppServices::class,
            BuildRouter::class,
        ], Kernel::STEPS);
    }

    public function testOkAppBootsCleanly(): void
    {
        $app = TestApp::bootFixture('ok-app');

        self::assertInstanceOf(App::class, $app);
        self::assertTrue($app->problems->isEmpty());
        self::assertSame('prod', $app->env); // config/.env wins over config/app.php's 'dev'
        self::assertSame('http://localhost:8080', $app->config->string('app.base_url', ''));
        self::assertSame('config/app.php', $app->config->provenance('app.base_url'));
    }

    public function testCoreServicesAreRegisteredFirstAndExactlyTheConstant(): void
    {
        $app = TestApp::bootFixture('ok-app');

        // The drift guard: the constant cannot silently diverge from the registration code.
        self::assertSame(
            Kernel::CORE_SERVICES,
            array_slice($app->container->ids(), 0, count(Kernel::CORE_SERVICES)),
        );
        self::assertContains(\App\Greeter::class, $app->container->ids());
        self::assertContains('greeting.name', $app->container->ids());
    }

    public function testOkAppResolvesItsOwnServices(): void
    {
        $app = TestApp::bootFixture('ok-app');

        $greeter = $app->container->get(\App\Greeter::class);
        self::assertSame('[prod] Hello, Lava!', $greeter->greet('Lava'));
        self::assertSame($greeter, $app->container->get(\App\Greeter::class)); // singleton
        self::assertSame('Lava', $app->container->get('greeting.name'));

        $record = $app->container->describe(\App\Greeter::class);
        self::assertSame('Services.php', basename($record->file)); // the wiring file, not the framework
    }

    public function testOkAppFlagResolvesThroughTheConfigLayer(): void
    {
        $app = TestApp::bootFixture('ok-app');

        $resolution = $app->features->resolve('beta_greeting');

        self::assertTrue($resolution->enabled);
        self::assertSame(FlagSource::Config, $resolution->source);
        self::assertSame('on', $resolution->setting);
        self::assertSame([
            ['layer' => 'code', 'setting' => 'rollout:50'],
            ['layer' => 'config', 'setting' => 'on'],
        ], $resolution->trace);
    }

    public function testEnvOverrideBeatsConfigAndBootIsHermetic(): void
    {
        $app = TestApp::bootFixture('ok-app', ['LAVA_FEATURE_BETA_GREETING' => 'off']);

        $resolution = $app->features->resolve('beta_greeting');
        self::assertFalse($resolution->enabled);
        self::assertSame(FlagSource::Env, $resolution->source);
        self::assertCount(3, $resolution->trace);

        // Hermetic: the override is gone when boot returns (any pre-existing value restored).
        self::assertNotSame('off', $_ENV['LAVA_FEATURE_BETA_GREETING'] ?? null);
        self::assertNotSame('off', getenv('LAVA_FEATURE_BETA_GREETING'));
    }

    public function testRealEnvironmentBeatsTheDotEnvFile(): void
    {
        $app = TestApp::bootFixture('ok-app', ['LAVA_ENV' => 'test']);

        self::assertSame('test', $app->env); // .env says prod; the real environment wins
        self::assertNotSame('test', getenv('LAVA_ENV'));
    }

    public function testMultiProblemAppReportsEverythingInOneBoot(): void
    {
        $result = TestApp::bootFixture('multi-problem-app');

        self::assertInstanceOf(BootFailure::class, $result);
        $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $result->problems->problems());
        self::assertSame(['invalid_env_file', 'unknown_feature', 'duplicate_service'], $codes);

        $text = $result->text();
        self::assertStringContainsString('LavaPHP found 3 problems (3 fatal, 0 warn)', $text);
        self::assertSame(3, substr_count($text, 'FIX:'));

        self::assertSame($result->problems->json(), json_decode($result->json(), true));

        // The duplicate names the user's exact registration lines — attribution
        // walks past the wiring closure's frames to app/Services.php itself.
        $duplicate = $result->problems->problems()[2];
        self::assertStringEndsWith('app/Services.php:9', $duplicate->context['first_registered_at']);
        self::assertStringEndsWith('app/Services.php:10', $duplicate->context['second_registered_at']);
        self::assertSame(10, $duplicate->source->line);
    }

    public function testBadFlagsAppCollectsEveryFlagProblem(): void
    {
        $result = TestApp::bootFixture('bad-flags-app');

        self::assertInstanceOf(BootFailure::class, $result);
        $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $result->problems->problems());
        self::assertSame(['duplicate_feature', 'unknown_feature'], $codes);

        $unknown = $result->problems->problems()[1];
        self::assertSame('beta_greeting', $unknown->context['nearest']);
        self::assertStringContainsString("Did you mean 'beta_greeting'", $unknown->fix);
    }

    public function testInvalidFlagValueFromEnvIsCollectedToo(): void
    {
        $result = TestApp::bootFixture('bad-flags-app', ['LAVA_FEATURE_BETA_GREETING' => 'banana']);

        self::assertInstanceOf(BootFailure::class, $result);
        $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $result->problems->problems());
        self::assertSame(['duplicate_feature', 'unknown_feature', 'invalid_flag_value'], $codes);
    }

    public function testMissingPackAndInvalidGatingReportTogether(): void
    {
        $result = TestApp::bootFixture('missing-pack-app');

        self::assertInstanceOf(BootFailure::class, $result);
        $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $result->problems->problems());
        self::assertSame(['missing_pack', 'invalid_gating'], $codes);

        $missing = $result->problems->problems()[0];
        self::assertStringContainsString('composer require lava/db', $missing->fix);
        self::assertSame('Modules.php', basename($missing->source->file));

        $gating = $result->problems->problems()[1];
        self::assertSame('rollout:50', $gating->context['setting']);
        self::assertStringContainsString('module Lava\View\ViewModule', $gating->getMessage());
    }

    public function testOkAppServesHttpThroughTheWholeStack(): void
    {
        $app = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $app);
        $client = new TestClient($app);

        $health = $client->get('/health');
        self::assertSame(200, $health->status());
        self::assertSame(['status' => 'ok'], $health->json());

        self::assertSame(200, $client->head('/health')->status());

        $user = $client->get('/users/42');
        self::assertSame(200, $user->status());
        $body = $user->json();
        self::assertSame(42, $body['user']['id']);
        self::assertSame(['global', 'auth'], $body['order']); // global middleware wraps route middleware
        self::assertSame('global', $user->header('X-Timing'));
        self::assertSame('yes', $user->header('X-Auth'));

        $greet = $client->get('/greet/ada');
        self::assertSame(200, $greet->status());
        self::assertSame('[prod] Hello, ada!', $greet->body()); // service injection + typed param + env from config/.env

        $beta = $client->get('/beta/dashboard');
        self::assertSame(200, $beta->status());
        self::assertSame(['dashboard' => 'beta'], $beta->json()); // beta_greeting is set on in config/features.php
    }

    public function testHttpProblemsAreJsonWithStatusAndFix(): void
    {
        $app = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $app);
        $client = new TestClient($app);

        $notFound = $client->get('/users/abc'); // {id:int} does not match 'abc'
        self::assertSame(404, $notFound->status());
        $problem = $notFound->json()['problems'][0];
        self::assertSame('route_not_found', $problem['code']);
        self::assertStringContainsString('lava routes --json', $problem['fix']);

        $notAllowed = $client->post('/users/42');
        self::assertSame(405, $notAllowed->status());
        self::assertSame('GET', $notAllowed->header('Allow'));
        $problem = $notAllowed->json()['problems'][0];
        self::assertSame('method_not_allowed', $problem['code']);
        self::assertSame(['GET'], $problem['context']['allowed']);
    }

    public function testGatedRouteIsAbsentWhileItsFlagIsOff(): void
    {
        $app = TestApp::bootFixture('ok-app', ['LAVA_FEATURE_BETA_GREETING' => 'off']);
        self::assertInstanceOf(App::class, $app);
        $client = new TestClient($app);

        $beta = $client->get('/beta/dashboard');
        self::assertSame(404, $beta->status());
        self::assertSame('route_not_found', $beta->json()['problems'][0]['code']);

        // Ungated routes are unaffected by the flag.
        self::assertSame(200, $client->get('/health')->status());
    }

    public function testRouterCarriesFrozenPlansAtBoot(): void
    {
        $app = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $app);

        self::assertSame(['health', 'users.show', 'greet', 'beta.dashboard'], $app->router->names());

        $plan = $app->router->plan('users.show');
        self::assertInstanceOf(HandlerPlan::class, $plan);
        self::assertSame('method', $plan->kind);
        self::assertSame(2, count($plan->injects));
        self::assertSame('App\Http\UserController::show', $plan->describe());

        $fnPlan = $app->router->plan('health');
        self::assertSame('function', $fnPlan->kind);
        self::assertSame("'App\\Http\\health'", $fnPlan->describe());
    }

    public function testBadRoutesAppCollectsEveryRouteProblemInOneBoot(): void
    {
        $result = TestApp::bootFixture('bad-routes-app');

        self::assertInstanceOf(BootFailure::class, $result);
        $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $result->problems->problems());
        self::assertSame(
            ['bad_route_pattern', 'bad_handler', 'bad_route_pattern', 'unknown_feature', 'bad_middleware'],
            $codes,
        );

        // The first problem is the one thrown inside app/Routes.php itself…
        $badPath = $result->problems->problems()[0];
        self::assertStringContainsString('paths must start with', $badPath->getMessage());

        // …and everything registered before the throw still got compiled and checked.
        $gate = $result->problems->problems()[3];
        self::assertStringContainsString('nope_flag', $gate->getMessage());

        $middleware = $result->problems->problems()[4];
        self::assertStringContainsString('GhostMiddleware', $middleware->getMessage());
        self::assertSame("route 'mw'", $middleware->context['used_by']);
    }
}