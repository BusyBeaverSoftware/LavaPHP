<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Http\HttpErrors;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A non-LavaProblem throwable escaped from user-authored code — a PHP error in
 * a config, services, or routes file (during boot), inside a handler a command
 * reached (during a CLI run), or inside a handler, middleware or subject
 * resolver answering a request. Wrapped so the report still renders
 * structurally instead of white-screening.
 *
 * The context keeps the throw site as `at`. The `source` is the first frame in
 * code the app wrote — the user artifact at fault, which for a library's throw
 * is the app's call into the library rather than the library's own line.
 */
final class UnexpectedFailure extends LavaProblem
{
    /** How many frames the trace a request failure carries outside production keeps. */
    private const TRACE_FRAMES = 10;

    public function code(): string
    {
        return 'unexpected_failure';
    }

    /**
     * The sentence and the fix name the step and nothing from the throwable,
     * for the reason {@see inRequest()} gives: in production a boot failure is
     * rendered to every client, and redaction withholds only the context. The
     * message and the location are in the context, which `lava check` prints
     * and which the response carries outside production.
     *
     * Generic in every environment, not only in `prod`: a step that throws
     * before `LoadConfig` does so while the environment is still the `dev`
     * default, so a sentence chosen by it could not be trusted to be the one
     * production renders.
     */
    public static function of(string $step, \Throwable $throwable): self
    {
        return new self(
            "Unexpected failure during boot step {$step}.",
            "Fix the exception this problem's context describes — it is not a LavaPHP wiring problem. "
                . 'In production the context is withheld from the response: run lava check on the server to print it.',
            [
                'step' => $step,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'at' => $throwable->getFile() . ':' . $throwable->getLine(),
            ],
            self::appFrame($throwable),
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
            self::appFrame($throwable),
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
     *
     * Outside production the context also carries a trimmed trace, so a failing
     * test shows the path to the throw. Production leaves it out: the log
     * receives the throwable itself, trace included.
     */
    public static function inRequest(ServerRequestInterface $request, \Throwable $throwable): self
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $context = [
            'method' => $method,
            'path' => $path,
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
            'at' => $throwable->getFile() . ':' . $throwable->getLine(),
        ];
        // Unrecorded means production, as it does for HttpErrors: the default
        // that shows less.
        $env = $request->getAttribute(HttpErrors::ENV_ATTRIBUTE);
        if (is_string($env) && $env !== '' && $env !== 'prod') {
            $context['trace'] = self::trace($throwable);
        }

        return new self(
            "Unexpected failure while handling {$method} {$path}.",
            "Fix the exception this problem's context describes — it is not a LavaPHP routing problem. "
                . 'In production the context is written to the log instead of the response.',
            $context,
            self::appFrame($throwable),
            $throwable,
        );
    }

    /**
     * The first frame in code the app wrote: the throw site when the app threw,
     * otherwise the app's call into whatever did. A frame under `vendor/` or in
     * this package is not the app's. Null when no frame is.
     */
    private static function appFrame(\Throwable $throwable): ?SourceLocation
    {
        $frames = [['file' => $throwable->getFile(), 'line' => $throwable->getLine()], ...$throwable->getTrace()];
        foreach ($frames as $frame) {
            if (!isset($frame['file']) || self::isFramework($frame['file'])) {
                continue;
            }

            return SourceLocation::of($frame['file'], $frame['line'] ?? 1);
        }

        return null;
    }

    private static function isFramework(string $file): bool
    {
        return str_contains($file, '/vendor/') || str_starts_with($file, dirname(__DIR__) . '/');
    }

    /** @return list<string> one `file:line function()` per frame, innermost first */
    private static function trace(\Throwable $throwable): array
    {
        $lines = [];
        foreach (array_slice($throwable->getTrace(), 0, self::TRACE_FRAMES) as $frame) {
            $call = isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function'] : $frame['function'];
            $where = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) : '[internal]';
            $lines[] = "{$where} {$call}()";
        }

        return $lines;
    }
}
