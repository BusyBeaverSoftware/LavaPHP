<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Features\FlagSource;
use Lava\Core\Problem\InvalidGating;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\MissingPack;

/**
 * The boot half of the gating rule: each app/Modules.php entry resolves its
 * feature here, with no subject. Results:
 *
 *   - audience flag (rollout/users) gating a module → InvalidGating, fatal —
 *     a per-user singleton is incoherent, so this is caught loudly, not 503'd;
 *   - feature off → the module is recorded disabled (M3 loads it manifest-only,
 *     so `lava routes --all` can still list its routes as disabled);
 *   - feature on but the module class doesn't exist → MissingPack, fatal, with
 *     the exact `composer require` command as the fix;
 *   - feature on and the class loads → enabled (M3's WireModules instantiates it).
 *
 * Every module is checked even after one fails — the report shows the whole
 * app's missing packs at once, not just the first.
 */
final class CheckModules implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        if ($ctx->features === null) {
            return; // a fatal upstream already stopped the chain
        }

        foreach ($ctx->moduleRefs as $ref) {
            try {
                $resolution = $ctx->features->resolve($ref->feature);
            } catch (LavaProblem $problem) {
                $ctx->problems->add($problem);
                continue;
            }

            if ($resolution->source === FlagSource::Subject) {
                $ctx->problems->add(InvalidGating::of(
                    $ref->feature,
                    $resolution->setting,
                    "module {$ref->moduleClass}",
                ));
                continue;
            }

            if (!$resolution->enabled) {
                $ctx->disabledModules[] = $ref;
                continue;
            }

            if (!class_exists($ref->moduleClass)) {
                $ctx->problems->add(MissingPack::of($ref));
                continue;
            }

            $ctx->enabledModules[] = $ref;
        }
    }
}