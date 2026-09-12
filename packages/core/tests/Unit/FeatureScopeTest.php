<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Features\FeatureScope;
use Lava\Core\Features\FlagSubject;
use Lava\Core\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * The scope holds request state in a container that outlives requests, so the
 * property that matters is that it always puts back what it found — after a
 * handler returns, after one throws, and when bindings nest.
 */
final class FeatureScopeTest extends TestCase
{
    public function testABindingLastsExactlyOneUnitOfWork(): void
    {
        $app = TestApp::bootFixture('subject-app');
        self::assertInstanceOf(App::class, $app);

        $scope = new FeatureScope($app->features);
        $pilot = $app->features->forSubject(new FlagSubject('u1'));

        self::assertFalse($scope->current()->on('team_preview'), 'outside a request, nobody is the subject');
        self::assertTrue($scope->during($pilot, static fn (): bool => $scope->current()->on('team_preview')));
        self::assertSame($app->features, $scope->current());
    }

    public function testAThrowStillRestoresTheScope(): void
    {
        $app = TestApp::bootFixture('subject-app');
        self::assertInstanceOf(App::class, $app);

        $scope = new FeatureScope($app->features);
        $pilot = $app->features->forSubject(new FlagSubject('u1'));

        try {
            $scope->during($pilot, static function (): never {
                throw new \RuntimeException('the handler failed');
            });
            self::fail('the throw should have escaped the scope');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame($app->features, $scope->current(), 'a failed request must not leak its subject');
    }

    public function testANestedBindingRestoresTheOuterOneRatherThanBoots(): void
    {
        $app = TestApp::bootFixture('subject-app');
        self::assertInstanceOf(App::class, $app);

        $scope = new FeatureScope($app->features);
        $pilot = $app->features->forSubject(new FlagSubject('u1'));
        $other = $app->features->forSubject(new FlagSubject('u2'));

        $scope->during($pilot, static function () use ($scope, $pilot, $other): void {
            $scope->during($other, static fn (): null => null);
            self::assertSame($pilot, $scope->current());
        });

        self::assertSame($app->features, $scope->current());
    }
}
