<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A per-env flag has no branch for the current environment. */
final class UnknownEnvBranch extends LavaProblem
{
    public function code(): string
    {
        return 'unknown_env_branch';
    }

    /** @param list<string> $definedBranches */
    public static function of(string $feature, string $env, array $definedBranches): self
    {
        return new self(
            "Feature '{$feature}' has no branch for env '{$env}'.",
            "Add the branch in config/features.php: Flag::env([…, '{$env}' => Flag::on()])."
            . " Defined branches: " . implode(', ', $definedBranches) . '.',
            ['feature' => $feature, 'env' => $env, 'defined_branches' => $definedBranches],
        );
    }
}