<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A non-LavaProblem throwable escaped during boot — typically a PHP error in a
 * user-authored file (config, services, routes). Wrapped so the boot report
 * still renders structurally instead of white-screening.
 */
final class UnexpectedFailure extends LavaProblem
{
    public function code(): string
    {
        return 'unexpected_failure';
    }

    public static function of(string $step, \Throwable $throwable): self
    {
        $at = $throwable->getFile() . ':' . $throwable->getLine();
        return new self(
            "Unexpected failure during boot step {$step}: {$throwable->getMessage()}",
            "Fix the underlying error at {$at} — it is not a LavaPHP wiring problem.",
            [
                'step' => $step,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'at' => $at,
            ],
            null,
            $throwable,
        );
    }
}