<?php

declare(strict_types=1);

namespace Lava\Core\Console;

use Lava\Core\Problem\ProblemCliRenderer;
use Lava\Core\Problem\ProblemReport;

/**
 * The dual writer: a command writes BOTH its human view (`text()`) and its
 * machine payload (`data()`) as it runs, and IO emits whichever the mode
 * selects. One code path, no `if ($json)` branch in any command — which is
 * what keeps the text and JSON views from drifting apart.
 *
 * The `--json` envelope shape lives in {@see Envelope}; problems always
 * render as the same problem object used by boot reports and HTTP bodies.
 */
final class IO
{
    /** @var array<string, mixed> payload fields, in the order data() was called */
    private array $payload = [];

    private string $text = '';

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly bool $json,
        private readonly bool $quiet,
        private $stdout,
        private $stderr,
    ) {
    }

    /** Wires to the real process streams — the only place STDOUT/STDERR appear. */
    public static function standard(bool $json, bool $quiet = false): self
    {
        return new self($json, $quiet, STDOUT, STDERR);
    }

    public function isJson(): bool
    {
        return $this->json;
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    /** Human view — a no-op in `--json` (and in `--quiet`) mode. */
    public function text(string $text): void
    {
        if (!$this->json && !$this->quiet) {
            $this->text .= $text;
        }
    }

    public function line(string $text = ''): void
    {
        $this->text($text . "\n");
    }

    /** Machine view — a no-op in text mode. */
    public function data(string $key, mixed $value): void
    {
        if ($this->json) {
            $this->payload[$key] = $value;
        }
    }

    /** Diagnostics go to stderr in BOTH modes, so `--json` stdout stays parseable. */
    public function error(string $text): void
    {
        fwrite($this->stderr, $text . "\n");
    }

    /**
     * Renders the accumulated view and returns the process exit code.
     *
     * @param bool $failed force a failed status for results that aren't
     *                     problems (e.g. `lava test` with red tests)
     */
    public function emit(string $command, ?ProblemReport $problems = null, bool $failed = false): int
    {
        $failed = $failed || ($problems !== null && $problems->hasFatals());

        if ($this->json) {
            $this->write(Envelope::encode(Envelope::of(
                $command,
                $failed ? 'failed' : 'ok',
                $this->payload,
                $problems?->json() ?? [],
            )));
        } else {
            $this->write($this->text);
            if ($problems !== null && !$problems->isEmpty()) {
                $this->write((new ProblemCliRenderer())->render($problems));
            }
        }

        return $failed ? ExitCode::Failure : ExitCode::Ok;
    }

    private function write(string $text): void
    {
        if ($text !== '') {
            fwrite($this->stdout, $text);
        }
    }
}
