<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * An audience-targeted flag (rollout/users) was used to gate a boot-lifetime
 * resource (a module). A per-user singleton is incoherent, and a runtime 503
 * from a wiring mistake is the silent failure class this framework exists to
 * eliminate — so this is caught loudly at boot instead.
 */
final class InvalidGating extends LavaProblem
{
    public function code(): string
    {
        return 'invalid_gating';
    }

    public static function of(string $feature, string $setting, string $gated): self
    {
        return new self(
            "Feature '{$feature}' ({$setting}) uses audience targeting and cannot gate {$gated}.",
            "Use Flag::on(), Flag::off(), or Flag::env([…]) for module gates;"
            . ' reserve rollout/users for per-request routes (->when()).',
            ['feature' => $feature, 'setting' => $setting, 'gated' => $gated],
        );
    }
}