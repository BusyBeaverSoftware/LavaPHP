<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\CommandTestCase;

/**
 * The inspection commands, exercised against the fixture apps the way an agent
 * would use them — through Console, in both views.
 *
 * The in-process harness (console, IO capture, environment isolation) lives in
 * {@see CommandTestCase}, shared with the other command tests.
 */
final class InspectionCommandsTest extends CommandTestCase
{
    public function testRoutesListsInjectionPlans(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['routes']);

        self::assertSame(ExitCode::Ok, $code);
        $routes = $envelope['data']['routes'];
        self::assertSame(
            ['health', 'users.show', 'greet', 'beta.dashboard'],
            array_column($routes, 'name'),
        );

        $greet = $routes[2];
        self::assertSame(['GET'], $greet['methods']);
        self::assertSame('App\Http\GreetController', $greet['handler']['class']);
        self::assertSame(
            ['args', 'greeter'],
            array_column($greet['injects'], 'name'),
        );
        self::assertSame(['args', 'service'], array_column($greet['injects'], 'kind'));
    }

    public function testRoutesHidesGatedOffRoutesUnlessAllIsGiven(): void
    {
        $this->withEnv(['LAVA_FEATURE_BETA_GREETING' => 'off'], function (): void {
            [, $hidden] = $this->json('ok-app', ['routes']);
            self::assertNotContains('beta.dashboard', array_column($hidden['data']['routes'], 'name'));

            [, $shown] = $this->json('ok-app', ['routes', '--all']);
            $routes = $shown['data']['routes'];
            self::assertContains('beta.dashboard', array_column($routes, 'name'));

            // Listed, but labelled — "why is my route a 404" is answered by
            // seeing the route and its flag, not by its absence.
            $beta = $routes[array_search('beta.dashboard', array_column($routes, 'name'), true)];
            self::assertSame('disabled', $beta['state']);
            self::assertSame('beta_greeting', $beta['feature']);
        });
    }

    public function testServicesReportsKindWiringSiteAndAliasTarget(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['services']);

        self::assertSame(ExitCode::Ok, $code);
        $byId = array_column($envelope['data']['services'], null, 'id');

        self::assertSame('singleton', $byId['App\Greeter']['kind']);
        self::assertStringEndsWith('ok-app/app/Services.php', $byId['App\Greeter']['file']);
        self::assertSame(9, $byId['App\Greeter']['line']);

        // The alias is reported as an alias, with the id it resolves to —
        // describe() follows aliases, so the CLI has to notice the difference.
        self::assertSame('alias', $byId['Psr\Log\LoggerInterface']['kind']);
        self::assertSame('Lava\Core\Log\LineLogger', $byId['Psr\Log\LoggerInterface']['target']);
    }

    public function testServicesListsOnlyWhatHandlersCanTypeHint(): void
    {
        [, $envelope] = $this->json('module-app', ['services']);

        $ids = array_column($envelope['data']['services'], 'id');
        // The pack registered Quota; the app's route handler is NOT a service.
        // HandlerInvoker and MiddlewarePipeline are built per request rather
        // than registered, precisely so this list stays honest.
        self::assertContains('Lava\DemoPack\Quota', $ids);
        self::assertNotContains('Lava\DemoPack\QuotaController', $ids);
    }

    public function testFeaturesReportsTheDecidingLayerAndFullTrace(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['features']);

        self::assertSame(ExitCode::Ok, $code);
        $flag = $envelope['data']['features'][0];
        self::assertSame('beta_greeting', $flag['name']);
        self::assertTrue($flag['enabled']);
        // The code default is rollout:50; config/features.php turns it on.
        self::assertSame('config', $flag['decided_by']);
        self::assertSame('on', $flag['setting']);
    }

    public function testFeaturesResolveTracesEveryLayer(): void
    {
        [, $envelope] = $this->json('ok-app', ['features', 'resolve', 'beta_greeting']);

        self::assertSame('beta_greeting', $envelope['data']['flag']['name']);
        self::assertSame(
            [
                ['layer' => 'code', 'setting' => 'rollout:50'],
                ['layer' => 'config', 'setting' => 'on'],
            ],
            $envelope['data']['flag']['trace'],
        );
    }

    public function testFeaturesResolveWithoutAFlagIsAUsageError(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['features', 'resolve']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame('bad_usage', $envelope['problems'][0]['code']);
        self::assertStringContainsString('lava features', $envelope['problems'][0]['fix']);
    }

    public function testUnknownFlagIsAReportNotAStackTrace(): void
    {
        // Regression: UnknownFeature thrown inside a command used to escape as
        // an uncaught fatal — the CLI must catch it exactly as boot does.
        [$code, $envelope] = $this->json('ok-app', ['features', 'resolve', 'beta_greetin']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('failed', $envelope['status']);
        self::assertSame('unknown_feature', $envelope['problems'][0]['code']);
        self::assertSame('beta_greeting', $envelope['problems'][0]['context']['nearest']);
        // The payload still carries this subcommand's keys.
        self::assertArrayHasKey('flag', $envelope['data']);
    }

    public function testConfigReportsProvenanceAndRedactsSecretLookingKeys(): void
    {
        [$code, $envelope] = $this->json('env-app', ['config']);

        self::assertSame(ExitCode::Ok, $code);
        $byKey = array_column($envelope['data']['config'], null, 'key');

        self::assertSame('config/app.php', $byKey['app.base_url']['from_file']);
        self::assertFalse($byKey['app.base_url']['secret']);
        self::assertSame('http://localhost:8080', $byKey['app.base_url']['value']);

        self::assertTrue($byKey['app.api_key']['secret']);
        self::assertNull($byKey['app.api_key']['value'], 'a secret must not reach the envelope');
    }

    public function testConfigRevealShowsTheRedactedValue(): void
    {
        [, $envelope] = $this->json('env-app', ['config', '--reveal']);

        $byKey = array_column($envelope['data']['config'], null, 'key');
        self::assertSame('sk_live_this_is_a_fixture_not_a_real_key', $byKey['app.api_key']['value']);
    }

    public function testConfigTextViewRedactsToo(): void
    {
        // Text and JSON must agree: the redaction is not a JSON-mode concern.
        $out = $this->text('env-app', ['config']);
        self::assertStringContainsString('<redacted>', $out);
        self::assertStringNotContainsString('sk_live_', $out);
    }

    public function testEnvShowsDeclaredAndUndeclaredVarsWithTheirSource(): void
    {
        [$code, $envelope] = $this->json('env-app', ['env']);

        self::assertSame(ExitCode::Ok, $code);
        $byName = array_column($envelope['data']['env'], null, 'name');

        self::assertSame(
            ['STRIPE_SECRET', 'APP_REGION', 'LEGACY_TOKEN', 'OPTIONAL_UNSET', 'UNCLAIMED'],
            array_column($envelope['data']['env'], 'name'),
        );
        self::assertSame('app/Services.php', $byName['STRIPE_SECRET']['declared_by']);
        self::assertSame('dotenv', $byName['APP_REGION']['source']);
        self::assertSame('eu-west-1', $byName['APP_REGION']['value']);
        // Nobody declared UNCLAIMED — it exists only in config/.env, and this
        // is the one view of the app where such an entry is visible at all.
        self::assertNull($byName['UNCLAIMED']['declared_by']);
        self::assertSame('dotenv', $byName['UNCLAIMED']['source']);
    }

    public function testEnvWarnsForARequiredUnsetVarWithoutFailing(): void
    {
        [$code, $envelope] = $this->json('env-app', ['env']);

        // Warn, not Fatal: the report is the point, and a diagnostic that
        // itself exits non-zero is an obstacle.
        self::assertSame(ExitCode::Ok, $code);
        self::assertSame('ok', $envelope['status']);
        self::assertSame('missing_env_var', $envelope['problems'][0]['code']);
        self::assertSame('warn', $envelope['problems'][0]['severity']);
        self::assertSame('STRIPE_SECRET', $envelope['problems'][0]['context']['name']);
    }

    public function testEnvDeclarationBeatsTheSecretNameHeuristic(): void
    {
        // LEGACY_TOKEN is named like a credential but declared as not one, and
        // OPTIONAL_UNSET is simply unset — neither may be redacted or warned.
        [, $envelope] = $this->json('env-app', ['env']);
        $byName = array_column($envelope['data']['env'], null, 'name');

        self::assertFalse($byName['LEGACY_TOKEN']['secret']);
        self::assertFalse($byName['OPTIONAL_UNSET']['required']);
    }

    public function testEnvReportsTheProcessEnvironmentAsTheSourceWhenItWins(): void
    {
        $this->withEnv(['STRIPE_SECRET' => 'sk_test_from_shell'], function (): void {
            [, $envelope] = $this->json('env-app', ['env']);
            $byName = array_column($envelope['data']['env'], null, 'name');

            self::assertSame('env', $byName['STRIPE_SECRET']['source']);
            // Secret: the value stays out of the envelope even though it is set.
            self::assertNull($byName['STRIPE_SECRET']['value']);
            self::assertSame([], $envelope['problems'], 'a set required var must not warn');
        });
    }

    public function testEnvRevealShowsASecretThatIsSet(): void
    {
        $this->withEnv(['STRIPE_SECRET' => 'sk_test_from_shell'], function (): void {
            [, $envelope] = $this->json('env-app', ['env', '--reveal']);
            $byName = array_column($envelope['data']['env'], null, 'name');
            self::assertSame('sk_test_from_shell', $byName['STRIPE_SECRET']['value']);
        });
    }

    public function testEnvEnvFlagIsReportedAsTheFlagsSource(): void
    {
        // `--env` overrides LAVA_ENV for the boot only, so afterwards the
        // process env holds no trace of it. Reporting "unset" beside a value
        // would be nonsense — the flag is the source.
        [, $envelope] = $this->json('ok-app', ['env', '--env=prod']);
        $byName = array_column($envelope['data']['env'], null, 'name');

        self::assertSame('flag', $byName['LAVA_ENV']['source']);
        self::assertSame('prod', $byName['LAVA_ENV']['value']);
        self::assertSame('prod', $envelope['data']['resolved_env']);
    }

    public function testAboutReportsPhpAndPacks(): void
    {
        [$code, $envelope] = $this->json('module-app', ['about']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame(PHP_VERSION, $envelope['data']['php']['version']);
        self::assertIsArray($envelope['data']['php']['extensions']);
        self::assertIsArray($envelope['data']['php']['pdo_drivers']);

        $pack = $envelope['data']['packs'][0];
        self::assertSame('lavaphp/demo-pack', $pack['package']);
        self::assertSame('demo_pack', $pack['feature']);
        self::assertSame('enabled', $pack['state']);
        self::assertTrue($pack['installed']);
        self::assertSame(['DEMO_API_KEY'], $pack['env_vars']);
    }

    public function testAboutStillAnswersWhenTheAppCannotBoot(): void
    {
        [$code, $envelope] = $this->json('broken-wiring-app', ['about']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('failed', $envelope['status']);
        // The runtime facts are exactly what you want when boot fails...
        self::assertSame(PHP_VERSION, $envelope['data']['php']['version']);
        // ...and the keys stay stable so a consumer can tell "no packs" from
        // "could not boot".
        self::assertNull($envelope['data']['app']);
        self::assertSame([], $envelope['data']['packs']);
        self::assertSame('service_not_registered', $envelope['problems'][0]['code']);
    }

    public function testDescribeResolvesRoutesServicesFlagsAndCommands(): void
    {
        [, $route] = $this->json('ok-app', ['describe', 'greet']);
        self::assertSame('route', $route['data']['kind']);
        self::assertSame('/greet/{name:word}', $route['data']['match']['path']);
        self::assertSame('App\Greeter', $route['data']['match']['injects'][1]['type']);

        [, $service] = $this->json('ok-app', ['describe', 'App\Greeter']);
        self::assertSame('service', $service['data']['kind']);
        self::assertSame('singleton', $service['data']['match']['kind']);

        [, $flag] = $this->json('ok-app', ['describe', 'beta_greeting']);
        self::assertSame('flag', $flag['data']['kind']);
        self::assertSame('config/features.php', $flag['data']['match']['declared_at']);

        [, $command] = $this->json('ok-app', ['describe', 'routes']);
        self::assertSame('command', $command['data']['kind']);
        self::assertSame('core', $command['data']['match']['pack']);
    }

    public function testDescribeAnUnknownSelectorListsEveryNamespace(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['describe', 'rutes']);

        self::assertSame(ExitCode::Failure, $code);
        $problem = $envelope['problems'][0];
        self::assertSame('unknown_selector', $problem['code']);
        // 'routes' is a command, and the suggestion search spans every
        // namespace — resolution order must not narrow the hint.
        self::assertSame('routes', $problem['context']['nearest']);
        foreach (['routes', 'services', 'flags', 'env', 'commands'] as $namespace) {
            self::assertArrayHasKey($namespace, $problem['context']);
        }
    }

    public function testDescribeWithoutASelectorIsAUsageError(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['describe']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame('bad_usage', $envelope['problems'][0]['code']);
    }

    public function testACommandOnABrokenAppStillEmitsItsOwnPayloadShape(): void
    {
        [$code, $envelope] = $this->json('broken-wiring-app', ['routes']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame([], $envelope['data']['routes']);
        self::assertSame(
            ['service_not_registered', 'unexpected_failure'],
            array_column($envelope['problems'], 'code'),
        );
    }
}
