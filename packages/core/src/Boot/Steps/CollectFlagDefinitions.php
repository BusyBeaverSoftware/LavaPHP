<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Features\Feature;
use Lava\Core\Features\FeatureSet;
use Lava\Core\Features\Flag;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;

/**
 * Builds the flag DEFINITIONS from two sources, in order:
 *
 *  1. app/Modules.php — each module's gate flag is provisionally defined as
 *     Flag::on() so 'set' overrides and MissingPack reports always have a
 *     name to attach to, even when the pack's code cannot load (that is the
 *     deliberate ModuleRef redundancy at work).
 *  2. config/features.php 'define' — app-owned flags. Defining a pack's flag
 *     here is a problem: pack flags belong to their pack.
 *
 * The 'set' section is stored raw on the context; BuildFeatures validates it
 * once definitions are complete.
 */
final class CollectFlagDefinitions implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        $set = new FeatureSet();
        $this->loadModuleRefs($ctx);
        $packFeatures = [];
        $keptRefs = [];
        foreach ($ctx->moduleRefs as $ref) {
            try {
                $set->add(
                    new Feature($ref->feature, Flag::on(), $ref->package, "gate for pack {$ref->package}"),
                    'app/Modules.php',
                );
            } catch (LavaProblem $problem) {
                // e.g. two modules gated by the same flag: recorded, and the
                // duplicate ref is dropped so CheckModules doesn't stack a
                // confusing second problem on the same entry.
                $ctx->problems->add($problem);
                continue;
            }
            $packFeatures[$ref->feature] = $ref->package;
            $keptRefs[] = $ref;
        }
        $ctx->moduleRefs = $keptRefs;

        $file = $ctx->configPath('features.php');
        if (is_file($file)) {
            $loaded = require $file;
            if (!is_array($loaded)) {
                $ctx->problems->add(new InvalidConfig(
                    'config/features.php must return an array with a define and/or set section.',
                    "End the file with: return ['define' => […], 'set' => […]];",
                    ['file' => 'config/features.php'],
                    SourceLocation::of($file, 1),
                ));
            } else {
                $this->absorb($ctx, $set, $loaded, $packFeatures);
            }
        }
        $ctx->featureDefinitions = $set;
    }

    private function loadModuleRefs(BootCtx $ctx): void
    {
        $file = $ctx->appPath('Modules.php');
        if (!is_file($file)) {
            return;
        }
        $list = require $file;
        if (!is_array($list)) {
            $ctx->problems->add(new InvalidConfig(
                'app/Modules.php must return a list of ModuleRef entries.',
                "Write: return [ ModuleRef::of(\\Lava\\Db\\DbModule::class, package: 'lava/db', feature: 'db'), … ];",
                ['file' => 'app/Modules.php'],
                SourceLocation::of($file, 1),
            ));
            return;
        }
        foreach ($list as $entry) {
            if (!$entry instanceof ModuleRef) {
                $ctx->problems->add(new InvalidConfig(
                    'app/Modules.php has an entry that is not a ModuleRef.',
                    "Every entry must be built by ModuleRef::of(\\Lava\\<Pack>\\<Pack>Module::class, package: 'lava/<pack>', feature: '<snake_case>').",
                    ['file' => 'app/Modules.php'],
                ));
                continue;
            }
            $ctx->moduleRefs[] = $entry;
        }
    }

    /**
     * @param array<mixed> $loaded
     * @param array<string, string> $packFeatures feature name => package
     */
    private function absorb(BootCtx $ctx, FeatureSet $set, array $loaded, array $packFeatures): void
    {
        foreach ($loaded as $section => $content) {
            if ($section === 'define') {
                $this->absorbDefine($ctx, $set, is_array($content) ? $content : null, $packFeatures);
            } elseif ($section === 'set') {
                $this->absorbSet($ctx, is_array($content) ? $content : null);
            } else {
                $ctx->problems->add(new InvalidConfig(
                    "config/features.php has an unknown section '{$section}'.",
                    "Only 'define' (app-owned Feature::define(…) entries) and 'set' (deployment Flag overrides) are allowed.",
                    ['section' => $section],
                ));
            }
        }
    }

    /**
     * @param array<mixed>|null $content
     * @param array<string, string> $packFeatures feature name => package, from app/Modules.php
     */
    private function absorbDefine(BootCtx $ctx, FeatureSet $set, ?array $content, array $packFeatures): void
    {
        if ($content === null) {
            $ctx->problems->add(new InvalidConfig(
                "config/features.php 'define' must be a list of Feature::define(…) entries.",
                "Write: 'define' => [ Feature::define('beta_ui', Flag::on()), … ],",
                ['section' => 'define'],
            ));
            return;
        }
        foreach ($content as $feature) {
            if (!$feature instanceof Feature) {
                $ctx->problems->add(new InvalidConfig(
                    "config/features.php 'define' has an entry that is not a Feature.",
                    "Write: Feature::define('name', Flag::on(), description: '…')",
                    ['section' => 'define'],
                ));
                continue;
            }
            if (isset($packFeatures[$feature->name])) {
                // unreachable in practice — Feature names unique per pack; kept for the clear message
                $ctx->problems->add(new InvalidConfig(
                    "Flag '{$feature->name}' is the gate for pack {$packFeatures[$feature->name]} and is defined by the pack itself.",
                    "Remove the 'define' entry for '{$feature->name}' — override it in 'set' instead if you need to.",
                    ['feature' => $feature->name, 'package' => $packFeatures[$feature->name]],
                ));
                continue;
            }
            try {
                $set->add($feature, 'config/features.php');
            } catch (LavaProblem $problem) {
                $ctx->problems->add($problem);
                continue;
            }
        }
    }

    /** @param array<mixed>|null $content */
    private function absorbSet(BootCtx $ctx, ?array $content): void
    {
        if ($content === null) {
            $ctx->problems->add(new InvalidConfig(
                "config/features.php 'set' must be a map of flag name => Flag.",
                "Write: 'set' => [ 'beta_ui' => Flag::rollout(25), … ],",
                ['section' => 'set'],
            ));
            return;
        }
        foreach ($content as $name => $flag) {
            if (!is_string($name) || !$flag instanceof Flag) {
                $ctx->problems->add(new InvalidConfig(
                    "config/features.php 'set' has a malformed entry.",
                    "Every entry must be 'flag_name' => Flag::… — e.g. 'beta_ui' => Flag::rollout(25).",
                    ['section' => 'set', 'entry' => $name],
                ));
                continue;
            }
            $ctx->rawFlagSet[$name] = $flag;
        }
    }
}