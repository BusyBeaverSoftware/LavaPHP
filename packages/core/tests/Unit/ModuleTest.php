<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Problem\ServiceNotRegistered;
use Lava\Core\Modules\ModuleCheck;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\ModuleMismatch;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * The module contract end to end: PackInfo is the pack's manifest, ModuleCheck
 * is the loud cross-check of the two places a pack's identity is declared, and
 * the module-app fixture proves wire → register → route → plan → dispatch —
 * plus the off state: a gated-off pack is absent, not disabled-in-place.
 */
final class ModuleTest extends TestCase
{
    public function testPackInfoValidatesItsIdentity(): void
    {
        $pack = PackInfo::of('lavaphp/demo-pack', 'demo_pack', envVars: ['DEMO_API_KEY']);

        self::assertSame([
            'package' => 'lavaphp/demo-pack',
            'feature' => 'demo_pack',
            'config_files' => [],
            'env_vars' => ['DEMO_API_KEY'],
        ], $pack->json());

        // One combined report lists every offending field at once.
        try {
            PackInfo::of('lavaphp/Bad', 'DemoPack');
            self::fail('InvalidConfig expected for a doubly-invalid PackInfo');
        } catch (InvalidConfig $problem) {
            self::assertSame('invalid_config', $problem->code());
            self::assertStringContainsString('package', $problem->getMessage());
            self::assertStringContainsString('feature', $problem->getMessage());
            self::assertStringContainsString('PackInfo::of', $problem->fix);
        }

        try {
            PackInfo::of('lavaphp/demo-pack', 'demo_pack', envVars: ['demo_api_key']);
            self::fail('InvalidConfig expected for a lowercase env var');
        } catch (InvalidConfig $problem) {
            self::assertStringContainsString('UPPER_SNAKE', $problem->getMessage());
        }
    }

    public function testCrossCheckDemandsIdentityAgreement(): void
    {
        $ref = ModuleRef::of(\Lava\DemoPack\DemoPackModule::class, package: 'lavaphp/demo-pack', feature: 'demo_pack');

        // Agreement is silent — that is the whole point of a cross-check.
        ModuleCheck::crossCheck($ref, PackInfo::of('lavaphp/demo-pack', 'demo_pack'));

        try {
            ModuleCheck::crossCheck($ref, PackInfo::of('lavaphp/demo-pack', 'other_feature'));
            self::fail('ModuleMismatch expected');
        } catch (ModuleMismatch $problem) {
            self::assertSame('module_mismatch', $problem->code());
            self::assertSame('demo_pack', $problem->context['entry']['feature']);
            self::assertSame('other_feature', $problem->context['pack']['feature']);
            self::assertSame(__FILE__, $problem->source->file); // the entry the user wrote, not the throw site
        }
    }

    public function testModuleAppWiresRegistersRoutesAndDispatches(): void
    {
        $app = TestApp::bootFixture('module-app');
        self::assertInstanceOf(App::class, $app);
        self::assertSame([], $app->problems->problems());

        // App routes register first, the module's after — on overlap the app wins.
        self::assertSame(['home', 'demo.quota'], $app->router->names());

        // The module's service was wired by the module, not by app/Services.php.
        self::assertTrue($app->container->has(\Lava\DemoPack\Quota::class));

        // The frozen plan injects the module's service into its own handler.
        $plan = $app->router->plan('demo.quota');
        self::assertSame(\Lava\DemoPack\Quota::class, $plan->injects[1]['type']);

        $client = new TestClient($app);
        $home = $client->get('/');
        self::assertSame(200, $home->status());
        self::assertSame(['home' => true], $home->json());

        $quota = $client->get('/demo/quota');
        self::assertSame(200, $quota->status());
        self::assertSame(['limit' => 42], $quota->json());
    }

    public function testPackOffMeansAbsentNotDisabledInPlace(): void
    {
        $app = TestApp::bootFixture('module-app', ['LAVA_FEATURE_DEMO_PACK' => 'off']);
        self::assertInstanceOf(App::class, $app);

        // Gated at boot: the module never registered its service.
        self::assertFalse($app->container->has(\Lava\DemoPack\Quota::class));

        $client = new TestClient($app);
        $quota = $client->get('/demo/quota');
        self::assertSame(404, $quota->status()); // gated per request: a real 404, never a 503
        self::assertSame('route_not_found', $quota->json()['problems'][0]['code']);

        self::assertSame(200, $client->get('/')->status()); // the app's own routes are unaffected
    }

    public function testAHandlerTakingAServiceNobodyRegisteredIsReportedAtItsRouteWithThePacksFeatureAsTheFix(): void
    {
        // Lava Notes R3-B12: the problem named no route, and with the pack off it
        // said to register the pack's own id, a duplicate_service once it is on.
        $dir = sys_get_temp_dir() . '/lava-r3-b12-' . bin2hex(random_bytes(6));
        mkdir($dir . '/app', 0777, true);
        $pack = TestApp::fixturePath('module-app') . '/app/pack/DemoPackModule.php';
        file_put_contents($dir . '/app/Modules.php', "<?php\nrequire_once " . var_export($pack, true) . ";\n"
            . "return [\\Lava\\Core\\Modules\\ModuleRef::of(\\Lava\\DemoPack\\DemoPackModule::class, package: 'lavaphp/demo-pack', feature: 'demo_pack')];\n");
        file_put_contents($dir . '/app/Routes.php', <<<'PHP'
            <?php
            if (!function_exists('lava_r3_b12_handler')) {
                function lava_r3_b12_handler(\Lava\Core\Testing\FrozenClock $clock): \Psr\Http\Message\ResponseInterface
                {
                    return \Lava\Core\Http\Responses::text('never');
                }
            }
            return function (\Lava\Core\Routing\Router $r): void {
                $r->get('/limit', 'limit')->handler([\Lava\DemoPack\QuotaController::class, 'show']);
                $r->get('/clock', 'clock')->handler('lava_r3_b12_handler');
            };
            PHP);

        try {
            $boot = TestApp::boot($dir, ['LAVA_FEATURE_DEMO_PACK' => 'off']);
            self::assertInstanceOf(BootFailure::class, $boot);
            $problems = array_values(array_filter($boot->problems->problems(), static fn ($p): bool => $p instanceof ServiceNotRegistered));
            self::assertCount(2, $problems, $boot->text());
            [$pack, $plain] = $problems;

            self::assertSame(['limit', 'lavaphp/demo-pack', 'demo_pack'], [$pack->context['route'], $pack->context['disabled_pack'], $pack->context['feature']]);
            self::assertStringContainsString('lavaphp/demo-pack is switched off in this environment (feature `demo_pack`)', $pack->fix);
            self::assertStringNotContainsString('app/Services.php', $pack->fix);
            self::assertSame([$dir . '/app/Routes.php', 9], [$pack->source?->file, $pack->source?->line]);

            self::assertSame('clock', $plain->context['route']);
            self::assertArrayNotHasKey('disabled_pack', $plain->context);
            self::assertStringContainsString('Register it in app/Services.php', $plain->fix, 'An id no pack owns keeps the ordinary fix.');
            self::assertSame([$dir . '/app/Routes.php', 10], [$plain->source?->file, $plain->source?->line]);
        } finally {
            @unlink($dir . '/app/Modules.php');
            @unlink($dir . '/app/Routes.php');
            @rmdir($dir . '/app');
            @rmdir($dir);
        }
    }
}