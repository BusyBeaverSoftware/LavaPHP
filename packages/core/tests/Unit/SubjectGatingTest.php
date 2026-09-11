<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Container\Container;
use Lava\Core\Features\FlagSource;
use Lava\Core\Features\FlagSubject;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * Audience gating end to end: ->when() on a users/rollout flag decides per
 * request, from the subject the app's FlagSubjectResolver derives. Anonymous
 * visitors resolve OFF — and with no resolver registered at all, every
 * audience flag is fail-closed, exactly as the gating rule promises.
 */
final class SubjectGatingTest extends TestCase
{
    public function testAudienceFlagsDecidePerRequestFromTheResolver(): void
    {
        $app = TestApp::bootFixture('subject-app');
        self::assertInstanceOf(App::class, $app);
        $client = new TestClient($app);

        self::assertSame(200, $client->get('/health')->status()); // ungated control

        // users targeting: the pilot is in, everyone else is not.
        $pilot = $client->get('/team/board', ['X-User-Id' => 'u1']);
        self::assertSame(200, $pilot->status());
        self::assertSame(['board' => 'team.board'], $pilot->json());
        self::assertSame(404, $client->get('/team/board', ['X-User-Id' => 'u2'])->status());
        self::assertSame(404, $client->get('/team/board')->status()); // anonymous → off

        // rollout:100 — every identified subject is in the bucket.
        $early = $client->get('/early', ['X-User-Id' => 'z9']);
        self::assertSame(200, $early->status());
        self::assertSame(['board' => 'early.features'], $early->json());
        self::assertSame(404, $client->get('/early')->status()); // anonymous → out

        // The resolution itself names the subject layer as the decider.
        $resolution = $app->features->forSubject(new FlagSubject('u1'))->resolve('team_preview');
        self::assertTrue($resolution->enabled);
        self::assertSame(FlagSource::Subject, $resolution->source);
    }

    public function testWithoutAResolverAudienceFlagsAreFailClosed(): void
    {
        $app = TestApp::bootFixture('subject-app');
        self::assertInstanceOf(App::class, $app);

        // The same app with the resolver stripped: an empty container is a
        // valid state — this fixture's handlers take no services, so
        // dispatch still works and only the gating changes.
        $bare = new App(
            $app->appDir,
            $app->env,
            $app->config,
            $app->features,
            new Container(),
            $app->problems,
            $app->router,
            $app->globalMiddleware,
        );
        $client = new TestClient($bare);

        // Even the pilot is locked out: no resolver means no audience flags.
        self::assertSame(404, $client->get('/team/board', ['X-User-Id' => 'u1'])->status());
        self::assertSame(404, $client->get('/early', ['X-User-Id' => 'u1'])->status());
        self::assertSame(200, $client->get('/health')->status());
    }
}