<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Features\Bucketing;
use Lava\Core\Features\Feature;
use Lava\Core\Features\FeatureSet;
use Lava\Core\Features\FeatureSettings;
use Lava\Core\Features\Features;
use Lava\Core\Features\Flag;
use Lava\Core\Features\FlagSource;
use Lava\Core\Features\FlagSubject;
use Lava\Core\Problem\DuplicateFeature;
use Lava\Core\Problem\InvalidFeatureName;
use Lava\Core\Problem\InvalidFlagValue;
use Lava\Core\Problem\UnknownEnvBranch;
use Lava\Core\Problem\UnknownFeature;
use PHPUnit\Framework\TestCase;

final class FeaturesTest extends TestCase
{
    public function testFlagNamedConstructorsProduceCanonicalSettings(): void
    {
        self::assertSame('on', Flag::on()->setting());
        self::assertSame('off', Flag::off()->setting());
        self::assertSame('rollout:25', Flag::rollout(25)->setting());
        self::assertSame('users:u1,u2', Flag::users('u1', 'u2')->setting());
        self::assertSame(
            'env:dev=on,prod=off',
            Flag::env(['dev' => Flag::on(), 'prod' => Flag::off()])->setting(),
        );
    }

    public function testParseIsTheInverseOfSetting(): void
    {
        foreach (['on', 'off', 'rollout:25', 'users:u1,u2', 'env:dev=on,prod=off'] as $syntax) {
            self::assertSame($syntax, Flag::parse($syntax)->setting(), $syntax);
        }
    }

    public function testInvalidFlagValuesAreProblems(): void
    {
        foreach (['banana', 'rollout:101', 'users:a,,b', 'env:dev'] as $syntax) {
            try {
                Flag::parse($syntax);
                self::fail("InvalidFlagValue expected for '{$syntax}'");
            } catch (InvalidFlagValue $problem) {
                self::assertSame('invalid_flag_value', $problem->code());
                self::assertStringContainsString(InvalidFlagValue::GRAMMAR, $problem->fix);
            }
        }
        // Constructor-level validation hits the same problem type.
        foreach ([[Flag::rollout(...), [-1]], [Flag::users(...), ['a,b']], [Flag::env(...), [[]]]] as [$ctor, $args]) {
            try {
                $ctor(...$args);
                self::fail('InvalidFlagValue expected');
            } catch (InvalidFlagValue $problem) {
                self::assertSame('invalid_flag_value', $problem->code());
            }
        }

        // The env-var spelling where a Flag belongs was a PHP warning, not a problem.
        foreach ([[['dev' => 'on'], "env:dev='on'"], [['prod' => 1], 'env:prod=int']] as [$branches, $value]) {
            try {
                Flag::env($branches);
                self::fail('InvalidFlagValue expected');
            } catch (InvalidFlagValue $problem) {
                self::assertSame($value, $problem->context['value']);
                self::assertStringContainsString('each branch must be a Flag, such as Flag::on()', $problem->getMessage());
            }
        }
    }

    public function testFeatureNamesMustBeSnakeCase(): void
    {
        foreach (['Bad', '2fast', 'with-dash', ''] as $name) {
            try {
                Feature::define($name, Flag::on());
                self::fail("InvalidFeatureName expected for '{$name}'");
            } catch (InvalidFeatureName $problem) {
                self::assertSame('invalid_feature_name', $problem->code());
            }
        }
    }

    public function testFeatureSetRejectsDuplicatesAndFindsNearest(): void
    {
        $set = new FeatureSet();
        $set->add(Feature::define('beta_greeting', Flag::on()), 'app/Modules.php');
        $set->add(Feature::define('typed_flag', Flag::off()), 'config/features.php');

        try {
            $set->add(Feature::define('beta_greeting', Flag::off()), 'config/features.php');
            self::fail('DuplicateFeature expected');
        } catch (DuplicateFeature $problem) {
            self::assertSame('duplicate_feature', $problem->code());
            self::assertSame('app/Modules.php', $problem->context['first_defined_at']);
            self::assertSame('config/features.php', $problem->context['second_defined_at']);
        }

        self::assertSame('beta_greeting', $set->nearest('beta_gretting'));
        self::assertSame('typed_flag', $set->nearest('typd_flag'));
        self::assertNull($set->nearest('something_else_entirely'));
    }

    public function testResolutionOrderIsCodeThenConfigThenEnv(): void
    {
        $definitions = new FeatureSet();
        $definitions->add(Feature::define('beta_ui', Flag::rollout(50)), 'src');
        $settings = new FeatureSettings();
        $settings->set('beta_ui', Flag::on(), FlagSource::Config, 'config/features.php');
        $settings->set('beta_ui', Flag::off(), FlagSource::Env, 'env:LAVA_FEATURE_BETA_UI');
        $features = new Features($definitions, $settings, null, 'dev');

        $resolution = $features->resolve('beta_ui');

        self::assertFalse($resolution->enabled);
        self::assertSame(FlagSource::Env, $resolution->source);
        self::assertSame('off', $resolution->setting);
        self::assertSame([
            ['layer' => 'code', 'setting' => 'rollout:50'],
            ['layer' => 'config', 'setting' => 'on'],
            ['layer' => 'env', 'setting' => 'off'],
        ], $resolution->trace);
    }

    public function testUndefinedNameIsFatalNeverSilentlyFalse(): void
    {
        $features = new Features(new FeatureSet(), new FeatureSettings(), null, 'dev');

        try {
            $features->on('anything');
            self::fail('UnknownFeature expected');
        } catch (UnknownFeature $problem) {
            self::assertSame('unknown_feature', $problem->code());
            self::assertArrayNotHasKey('nearest', $problem->context);
        }
    }

    public function testBucketingPinsTheDocumentedStickyFormula(): void
    {
        self::assertSame(crc32('beta_ui' . "\x1f" . 'u42') % 100, Bucketing::of('beta_ui', 'u42'));
        self::assertSame(Bucketing::of('beta_ui', 'u42'), Bucketing::of('beta_ui', 'u42'));
        self::assertLessThan(100, Bucketing::of('beta_ui', 'u42'));
    }

    public function testRolloutBoundariesAndTheAnonymousPolicy(): void
    {
        $mk = static function (Flag $flag, ?FlagSubject $subject): Features {
            $definitions = new FeatureSet();
            $definitions->add(Feature::define('r', $flag), 'src');
            return new Features($definitions, new FeatureSettings(), $subject, 'dev');
        };
        $subject = new FlagSubject('u42');

        self::assertTrue($mk(Flag::rollout(100), $subject)->on('r'));
        self::assertFalse($mk(Flag::rollout(0), $subject)->on('r'));
        // Anonymous subjects resolve OFF for audience flags — documented policy.
        self::assertFalse($mk(Flag::rollout(100), null)->on('r'));
        self::assertSame(FlagSource::Subject, $mk(Flag::rollout(100), null)->resolve('r')->source);
    }

    public function testUsersAllowListAndForSubject(): void
    {
        $definitions = new FeatureSet();
        $definitions->add(Feature::define('u', Flag::users('u1', 'u2')), 'src');
        $features = new Features($definitions, new FeatureSettings(), null, 'dev');

        self::assertTrue($features->forSubject(new FlagSubject('u1'))->on('u'));
        self::assertFalse($features->forSubject(new FlagSubject('u3'))->on('u'));
        self::assertFalse($features->on('u'));
    }

    public function testPerEnvBranchesResolveOrFailLoudly(): void
    {
        $definitions = new FeatureSet();
        $definitions->add(
            Feature::define('e', Flag::env(['dev' => Flag::on(), 'prod' => Flag::off()])),
            'src',
        );
        $mk = static fn (string $env): Features => new Features($definitions, new FeatureSettings(), null, $env);

        self::assertTrue($mk('dev')->on('e'));
        self::assertFalse($mk('prod')->on('e'));
        self::assertSame([
            ['layer' => 'code', 'setting' => 'env:dev=on,prod=off'],
            ['layer' => 'env:dev', 'setting' => 'on'],
        ], $mk('dev')->resolve('e')->trace);

        try {
            $mk('staging')->on('e');
            self::fail('UnknownEnvBranch expected');
        } catch (UnknownEnvBranch $problem) {
            self::assertSame('unknown_env_branch', $problem->code());
            self::assertStringContainsString('staging', $problem->getMessage());
            self::assertSame(['dev', 'prod'], $problem->context['defined_branches']);
            self::assertStringContainsString("'staging' => Flag::on()", $problem->fix);
        }
    }
}