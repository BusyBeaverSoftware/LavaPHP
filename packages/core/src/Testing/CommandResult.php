<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

/**
 * What a {@see TestConsole} run produced: the exit code, both streams, and the
 * `--json` envelope decoded — the three things an agent reads from `lava`.
 */
final class CommandResult
{
    public function __construct(
        private readonly int $exitCode,
        private readonly string $output,
        private readonly string $errors,
    ) {
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /** Everything the command wrote to stdout. */
    public function output(): string
    {
        return $this->output;
    }

    /** Everything the command wrote to stderr. */
    public function errors(): string
    {
        return $this->errors;
    }

    /**
     * The decoded `--json` envelope: `schema`, `command`, `status`, `data`, `problems`.
     *
     * @return array<string, mixed>
     * @throws \UnexpectedValueException when stdout is not an envelope — the command was run without json()
     */
    public function envelope(): array
    {
        $decoded = json_decode($this->output, true);
        $envelope = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $envelope[$key] = $value;
                }
            }
        }

        if ($envelope === []) {
            throw new \UnexpectedValueException(
                'The command did not write a JSON envelope — run it through TestConsole::json(). It wrote: '
                . substr($this->output, 0, 200),
            );
        }

        return $envelope;
    }

    /**
     * The envelope's `data` — the command's own payload.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->envelope()['data'] ?? null;
        $payload = [];
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $payload[$key] = $value;
                }
            }
        }

        return $payload;
    }

    /**
     * The code of every problem the command reported, in order.
     *
     * @return list<string>
     */
    public function problemCodes(): array
    {
        $problems = $this->envelope()['problems'] ?? null;
        $codes = [];
        if (is_array($problems)) {
            foreach ($problems as $problem) {
                if (is_array($problem) && is_string($problem['code'] ?? null)) {
                    $codes[] = $problem['code'];
                }
            }
        }

        return $codes;
    }
}
