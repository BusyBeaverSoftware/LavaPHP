<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A non-LavaProblem throwable escaped from user-authored code — a PHP error in
 * a config, services, or routes file (during boot), inside a handler a command
 * reached (during a CLI run), or inside a handler, middleware or subject
 * resolver answering a request. Wrapped so the report still renders
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

    /**
     * The same wrapper for a request. Without it a handler that threw a
     * `PDOException` or a `TypeError` left `App::handle()` as PHP's uncaught
     * exception: an empty 500, a dead test run, or — with `display_errors` on —
     * a 200 carrying the message and the stack trace.
     *
     * The sentence and the fix say nothing specific on purpose. In production
     * they are all a client sees, and an exception's message is exactly where a
     * driver puts a query or a credential. The specifics are in the context,
     * which production withholds from the response and writes to the log.
     */
    public static function inRequest(ServerRequestInterface $request, \Throwable $throwable): self
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        return new self(
            "Unexpected failure while handling {$method} {$path}.",
            "Fix the exception this problem's context describes — it is not a LavaPHP routing problem. "
                . 'In production the context is written to the log instead of the response.',
            [
                'method' => $method,
                'path' => $path,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'at' => $throwable->getFile() . ':' . $throwable->getLine(),
            ],
            null,
            $throwable,
        );
    }
}