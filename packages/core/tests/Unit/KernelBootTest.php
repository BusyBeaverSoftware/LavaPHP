<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Boot\Kernel;
use Lava\Core\Boot\Steps\BuildFeatures;
use Lava\Core\Boot\Steps\BuildRouter;
use Lava\Core\Boot\Steps\CheckAppDir;
use Lava\Core\Boot\Steps\CheckModules;
use Lava\Core\Boot\Steps\CollectFlagDefinitions;
use Lava\Core\Boot\Steps\LoadConfig;
use Lava\Core\Boot\Steps\LoadDotEnv;
use Lava\Core\Boot\Steps\LoadPackConfig;
use Lava\Core\Boot\Steps\RegisterCommands;
use Lava\Core\Boot\Steps\RegisterCoreServices;
use Lava\Core\Boot\Steps\ValidateWiring;
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
            CheckAppDir::class,
            LoadDotEnv::class,
            LoadConfig::class,
            CollectFlagDefinitions::class,
            BuildFeatures::class,
            CheckModules::class,
            LoadPackConfig::class,
            RegisterCoreServices::class,
            WireModules::class,
            WireAppServices::class,
            BuildRouter::class,
            RegisterCommands::class,
            ValidateWiring::class,
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

    /**
     * Booting a directory that is not an app must FAIL, not silently succeed
     * with an empty one. Every artifact in conventions.md is optional, so
     * without CheckAppDir a random directory boots clean and `lava routes`
     * there answers `status: ok, routes: []` — which reads as "your app has no
     * routes" rather than "there is no app here".
     */
    public function testBootingADirectoryThatIsNotAnAppIsAProblem(): void
    {
        $dir = sys_get_temp_dir() . '/lava-not-an-app-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir));

        try {
            $result = TestApp::boot($dir);

            self::assertInstanceOf(BootFailure::class, $result);
            $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $result->problems->problems());
            // Exactly one problem: nothing downstream of "there is no app"
            // should invent findings of its own.
            self::assertSame(['not_an_app'], $codes);

            $problem = $result->problems->problems()[0];
            self::assertSame($dir, $problem->context['app_dir']);
            self::assertSame(['app', 'config', 'public/index.php'], $problem->context['expected']);

            // The marker set is generous, not a ban: ONE of the three is
            // enough. A config-only app is legitimate (bad-flags-app is one),
            // so an empty config/ directory must boot green.
            self::assertTrue(mkdir($dir . '/config'));
            self::assertInstanceOf(App::class, TestApp::boot($dir));
        } finally {
            @rmdir($dir . '/config');
            @rmdir($dir);
        }
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
        // Fixture-rot guard. missing_pack only fires while this class is
        // absent, so the day it lands the assertions below fail for a reason
        // that has nothing to do with what they test. Fail here instead, where
        // the cause is one line away.
        self::assertFalse(
            class_exists('Lava\Search\SearchModule'),
            'missing-pack-app needs a module class that does not exist; pick another fictional pack.',
        );

        $result = TestApp::bootFixture('missing-pack-app');

        self::assertInstanceOf(BootFailure::class, $result);
        $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $result->problems->problems());
        self::assertSame(['missing_pack', 'invalid_gating'], $codes);

        $missing = $result->problems->problems()[0];
        self::assertStringContainsString('composer require lava/search', $missing->fix);
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

    public function testBrokenWiringAppFailsAtBootWithEveryBrokenRegistration(): void
    {
        $result = TestApp::bootFixture('broken-wiring-app');

        self::assertInstanceOf(BootFailure::class, $result);
        $codes = array_map(static fn (LavaProblem $p): string => $p->code(), $result->problems->problems());
        self::assertSame(['service_not_registered', 'unexpected_failure'], $codes);

        // The missing id is attributed to the factory's own wiring line — the
        // container names the registration whose factory asked for it, so the
        // fix lands in the user's file, not the framework's.
        $missing = $result->problems->problems()[0];
        self::assertSame('never.registered', $missing->context['id']);
        self::assertStringEndsWith('app/Services.php:11', $missing->context['referenced_from']);

        // A plain exception inside a constructor is still a structured problem
        // naming the step, the exception, and the user's file:line.
        $boom = $result->problems->problems()[1];
        self::assertSame(ValidateWiring::class, $boom->context['step']);
        self::assertSame(\LogicException::class, $boom->context['exception']);
        self::assertStringEndsWith('app/Wiring/Boom.php:12', $boom->context['at']);
        self::assertStringContainsString('not a LavaPHP wiring problem', $boom->fix);
    }

    public function testRedefiningAPackGateFlagInConfigIsReported(): void
    {
        $result = TestApp::bootFixture('redefined-pack-flag-app');

        self::assertInstanceOf(BootFailure::class, $result);
        $redefinition = null;
        foreach ($result->problems->problems() as $problem) {
            if (($problem->context['feature'] ?? null) === 'redefined_pack') {
                $redefinition = $problem;
            }
        }
        self::assertNotNull($redefinition, 'expected the pack-flag redefinition problem');
        self::assertSame('invalid_config', $redefinition->code());
        self::assertSame('lava/redefined-pack', $redefinition->context['package']);
        self::assertStringContainsString('is the gate for pack lava/redefined-pack', $redefinition->getMessage());
        self::assertStringContainsString("override it in 'set'", $redefinition->fix);
    }
}