<?php

declare(strict_types=1);

namespace Lava\View\Tests\Support;

use Lava\Core\Features\Feature;
use Lava\Core\Features\Features;
use Lava\Core\Features\FeatureSet;
use Lava\Core\Features\FeatureSettings;
use Lava\Core\Features\Flag;

/**
 * A `Features` resolver built from a plain map, for tests that need one and do
 * not care how it was assembled.
 *
 * The pack's unit tests are about templates and about how the module reads
 * config, not about flag resolution — that is lava/core's subject and it has
 * its own tests. What these tests need is a resolver that answers correctly for
 * the two or three names they mention, without a boot.
 */
final class Flags
{
    /**
     * @param array<string, Flag> $flags name => default
     */
    public static function of(array $flags = [], string $env = 'dev'): Features
    {
        $definitions = new FeatureSet();
        foreach ($flags as $name => $flag) {
            $definitions->add(Feature::define($name, $flag), 'config/features.php');
        }

        return new Features($definitions, new FeatureSettings(), null, $env);
    }
}
