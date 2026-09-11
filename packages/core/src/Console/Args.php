<?php

declare(strict_types=1);

namespace Lava\Core\Console;

/**
 * Parsed argv tokens for one command invocation.
 *
 * Value flags take their value with `=` (`--env=prod`), never as the next
 * token: `--flag value` is ambiguous with a positional argument and guessing
 * would make `lava describe --json users.show` swallow the selector. One
 * unambiguous spelling is a contract an agent can rely on.
 *
 * `-q`/`-h` short flags are accepted for the two flags humans type most; they
 * parse into the same flag map as their long forms.
 */
final class Args
{
    /**
     * @param list<string> $positional
     * @param array<string, string|true> $flags
     */
    private function __construct(
        private readonly array $positional,
        private readonly array $flags,
    ) {
    }

    /** @param list<string> $argv tokens AFTER the command name (argv[0]/argv[1] removed) */
    public static function parse(array $argv): self
    {
        $positional = [];
        $flags = [];
        $literal = false; // everything after a bare `--` is positional

        foreach ($argv as $token) {
            if ($literal) {
                $positional[] = $token;
                continue;
            }
            if ($token === '--') {
                $literal = true;
                continue;
            }
            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                if ($body === '') {
                    continue;
                }
                if (str_contains($body, '=')) {
                    [$name, $value] = explode('=', $body, 2);
                    $flags[$name] = $value;
                } else {
                    $flags[$body] = true;
                }
                continue;
            }
            if (str_starts_with($token, '-') && strlen($token) > 1) {
                $name = substr($token, 1);
                $flags[self::SHORT[$name] ?? $name] = true;
                continue;
            }
            $positional[] = $token;
        }

        return new self($positional, $flags);
    }

    /** The two short flags worth accepting; everything else is long-only. */
    private const SHORT = ['q' => 'quiet', 'h' => 'help', 'j' => 'json'];

    /** @return list<string> positional arguments, in order */
    public function positional(): array
    {
        return $this->positional;
    }

    public function arg(int $index): ?string
    {
        return $this->positional[$index] ?? null;
    }

    /** True when the flag appears, whether or not it carries a value. */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->flags);
    }

    /** The `--name=value` payload, or null when absent or value-less. */
    public function value(string $name): ?string
    {
        $raw = $this->flags[$name] ?? null;
        return is_string($raw) ? $raw : null;
    }

    public function bool(string $name): bool
    {
        return $this->has($name);
    }

    /** @return array<string, string|true> the raw flag map (for diagnostics) */
    public function flags(): array
    {
        return $this->flags;
    }
}
