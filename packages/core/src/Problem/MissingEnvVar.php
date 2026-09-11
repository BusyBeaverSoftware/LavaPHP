<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A declared environment variable has no value — not in the process
 * environment, not in config/.env.
 *
 * Severity is Warn, deliberately: `lava env` is a report, and an app may
 * legitimately boot without a var its pack reads lazily. Failing the command
 * would make the diagnostic itself the obstacle; `lava check --strict` is
 * where an unset required var becomes a build failure.
 */
final class MissingEnvVar extends LavaProblem
{
    public static function of(string $name, string $declaredBy): self
    {
        return new self(
            "Required environment variable {$name} is not set.",
            "Set {$name} in config/.env, or export it in the process environment.",
            ['name' => $name, 'declared_by' => $declaredBy],
        );
    }

    public function code(): string
    {
        return 'missing_env_var';
    }

    public function severity(): Severity
    {
        return Severity::Warn;
    }
}
