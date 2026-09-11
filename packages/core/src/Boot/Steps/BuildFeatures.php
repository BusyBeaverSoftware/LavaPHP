<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Features\FeatureSettings;
use Lava\Core\Features\Features;
use Lava\Core\Features\Flag;
use Lava\Core\Features\FlagSource;
use Lava\Core\Problem\InvalidFlagValue;
use Lava\Core\Problem\UnknownFeature;

/**
 * Stacks the deployment overrides onto the definitions, then builds the
 * resolver. Layer order is fixed and identical to the resolution order the
 * resolver replays: config/features.php 'set' first, then
 * LAVA_FEATURE_<UPPER_SNAKE> env variables — so env always gets the last word.
 *
 * Unknown flag names in either layer are fatal (never silently ignored):
 * a typo in a gate is the classic silent failure this framework exists to catch.
 */
final class BuildFeatures implements BootStep
{
    private const ENV_PREFIX = 'LAVA_FEATURE_';

    public function run(BootCtx $ctx): void
    {
        if ($ctx->featureDefinitions === null) {
            return; // a fatal upstream already stopped the chain
        }
        $definitions = $ctx->featureDefinitions;
        $settings = new FeatureSettings();

        foreach ($ctx->rawFlagSet as $name => $flag) {
            if (!$definitions->has($name)) {
                $ctx->problems->add(UnknownFeature::of($name, $definitions->nearest($name)));
                continue;
            }
            $settings->set($name, $flag, FlagSource::Config, 'config/features.php');
        }

        foreach ($this->envFeatureVars($ctx) as $var => $value) {
            $name = strtolower(substr($var, strlen(self::ENV_PREFIX)));
            try {
                $flag = Flag::parse($value);
            } catch (InvalidFlagValue $problem) {
                $ctx->problems->add($problem);
                continue;
            }
            if (!$definitions->has($name)) {
                $ctx->problems->add(UnknownFeature::of($name, $definitions->nearest($name)));
                continue;
            }
            $settings->set($name, $flag, FlagSource::Env, "env:{$var}");
        }

        $ctx->featureSettings = $settings;
        $ctx->features = new Features($definitions, $settings, null, $ctx->env);
    }

    /**
     * Every LAVA_FEATURE_* variable visible from any environment source,
     * sorted by name (deterministic reports), with its effective value.
     *
     * @return array<string, string> variable name => value
     */
    private function envFeatureVars(BootCtx $ctx): array
    {
        $names = [];
        $collect = static function (array $keys) use (&$names): void {
            foreach ($keys as $key) {
                if (is_string($key) && str_starts_with($key, self::ENV_PREFIX)) {
                    $names[$key] = true;
                }
            }
        };
        $collect(array_keys($_ENV));
        $collect(array_keys($_SERVER));
        $collect(array_keys(getenv()));
        ksort($names);

        $out = [];
        foreach (array_keys($names) as $var) {
            $value = $ctx->envValue($var);
            if ($value !== null) {
                $out[$var] = $value;
            }
        }
        return $out;
    }
}