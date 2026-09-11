<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A non-LavaProblem throwable escaped from user-authored code — a PHP error in
 * a config, services, or routes file (during boot), or inside a handler a
 * command reached (during a CLI run). Wrapped so the report still renders
 * structurally instead of white-screening.
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

    /**
     * The same wrapper for a CLI run. A command's failure is never a boot
     * failure, and saying "boot step" for a broken `lava routes` would send the
     * reader looking in the wrong file — so the two get their own wording.
     */
    public static function inCommand(string $command, \Throwable $throwable): self
    {
        $at = $throwable->getFile() . ':' . $throwable->getLine();
        return new self(
            "Unexpected failure while running 'lava {$command}': {$throwable->getMessage()}",
            "Fix the underlying error at {$at} — it is not a LavaPHP CLI problem.",
            [
                'command' => $command,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'at' => $at,
            ],
            null,
            $throwable,
        );
    }
}