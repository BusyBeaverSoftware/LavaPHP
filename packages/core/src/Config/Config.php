<?php

declare(strict_types=1);

namespace Lava\Core\Config;

use Lava\Core\Problem\InvalidConfig;

/**
 * Immutable config bag. Keys are "<file>.<key>": "app.base_url" is the
 * "base_url" key of config/app.php. Provenance (which file set each key)
 * is recorded so `lava config` can answer "where did this value come from?".
 */
final class Config
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $provenance key => relative file path
     */
    public function __construct(
        private readonly array $values = [],
        private readonly array $provenance = [],
    ) {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Required accessors: a missing key or a wrong type is a Problem, never a
     * silent null.
     *
     * Each of these narrows the value it read and says so in its own body,
     * rather than handing a predicate to a shared helper. That is a few more
     * lines, and it buys two things worth more than the lines: the type a caller
     * gets back is visible where it is enforced, and the label and the check
     * cannot drift apart. A helper called as `('string', is_int(...))` type-checks
     * fine and then hands an `int` to something that declared `string` — the
     * caller reads the declaration, so the declaration has to be the enforcement.
     */
    public function needString(string $key): string
    {
        $value = $this->requiredValue($key, 'string');
        if (!is_string($value)) {
            $this->wrongType($key, 'string', $value);
        }

        return $value;
    }

    public function needInt(string $key): int
    {
        $value = $this->requiredValue($key, 'integer');
        if (!is_int($value)) {
            $this->wrongType($key, 'integer', $value);
        }

        return $value;
    }

    public function needBool(string $key): bool
    {
        $value = $this->requiredValue($key, 'boolean');
        if (!is_bool($value)) {
            $this->wrongType($key, 'boolean', $value);
        }

        return $value;
    }

    /** @return array<mixed> */
    public function needArray(string $key): array
    {
        $value = $this->requiredValue($key, 'array');
        if (!is_array($value)) {
            $this->wrongType($key, 'array', $value);
        }

        return $value;
    }

    /** Optional accessors: a missing key uses the given default; a wrong type is still a Problem. */
    public function string(string $key, string $default): string
    {
        $value = $this->optionalValue($key, $default);
        if (!is_string($value)) {
            $this->wrongType($key, 'string', $value);
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->optionalValue($key, $default);
        if (!is_int($value)) {
            $this->wrongType($key, 'integer', $value);
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->optionalValue($key, $default);
        if (!is_bool($value)) {
            $this->wrongType($key, 'boolean', $value);
        }

        return $value;
    }

    /**
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public function array(string $key, array $default): array
    {
        $value = $this->optionalValue($key, $default);
        if (!is_array($value)) {
            $this->wrongType($key, 'array', $value);
        }

        return $value;
    }

    /** @return list<string> every key, in load order */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function provenance(string $key): ?string
    {
        return $this->provenance[$key] ?? null;
    }

    /**
     * Immutable merge used by boot. A key set by two files is a Problem —
     * collisions can't happen by construction (keys are prefixed by filename),
     * so seeing one means someone bypassed the convention.
     */
    public function with(string $key, mixed $value, string $fromFile): self
    {
        if (array_key_exists($key, $this->values)) {
            throw new InvalidConfig(
                "Config key '{$key}' is set by two files.",
                "Keep each key in exactly one config file. '{$key}' was set by {$this->provenance[$key]}"
                . " and again by {$fromFile}.",
                ['key' => $key, 'first_file' => $this->provenance[$key], 'second_file' => $fromFile],
            );
        }
        return new self(
            [...$this->values, $key => $value],
            [...$this->provenance, $key => $fromFile],
        );
    }

    /** @return list<array{key: string, value: mixed, from_file: string|null}> — feeds `lava config --json`. */
    public function json(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            $out[] = ['key' => $key, 'value' => $value, 'from_file' => $this->provenance[$key] ?? null];
        }
        return $out;
    }

    /**
     * The raw value of a required key, or a Problem naming the file that should
     * have set it.
     *
     * Returns `mixed` on purpose: the type is enforced by the accessor that
     * called this, one line later, where the narrowing is visible.
     */
    private function requiredValue(string $key, string $expected): mixed
    {
        // Split before the branch: the message below needs $file too.
        [$file, $name] = $this->splitKey($key);
        if (!$this->has($key)) {
            throw new InvalidConfig(
                "Required config key '{$key}' is not set.",
                "Set '{$name}' in config/{$file}.php, or read it with a default: \$config->"
                . (self::OPTIONAL_ACCESSORS[$expected] ?? $expected) . "('{$key}', …)."
                . $this->unreadFileNote($file),
                ['key' => $key, 'expected' => $expected],
            );
        }

        return $this->values[$key];
    }

    /**
     * The optional accessor for each type label. The label is what
     * `context.expected` reports — `integer`, the word a reader expects — and the
     * method is `int()`. Deriving one from the other printed `$config->integer()`,
     * a method that does not exist, in the fix meant to repair the call.
     */
    private const OPTIONAL_ACCESSORS = [
        'string' => 'string',
        'integer' => 'int',
        'boolean' => 'bool',
        'array' => 'array',
    ];

    /**
     * Why a key was never going to be found, when no key at all came from its file.
     *
     * Core reads `config/app.php` and `config/logging.php`; a pack reads only the
     * files it declares, and only while it is enabled (decision 102). A key in any
     * other file is not misspelt — it is in a file nothing opens — so "set it in
     * config/cache.php" would send the reader to create a file that changes
     * nothing. A file some key did come from is plainly read, and needs no note.
     */
    private function unreadFileNote(string $file): string
    {
        if (in_array($file, ['app', 'logging'], true)) {
            return '';
        }
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $file . '.')) {
                return '';
            }
        }

        return " No key came from config/{$file}.php: core reads config/app.php and config/logging.php, "
            . 'and a pack reads only the config files it declares, while it is enabled.';
    }

    /**
     * The raw value of an optional key, or the caller's own default when the key
     * is absent.
     *
     * Absent is `array_key_exists`, not `??`: a config file that sets a key to
     * null has set it, and a null is a wrong type the accessor should report
     * rather than a missing key it should quietly paper over.
     */
    private function optionalValue(string $key, mixed $default): mixed
    {
        return $this->has($key) ? $this->values[$key] : $default;
    }

    /**
     * A value that was read and is the wrong type.
     *
     * `never`, so a caller that checks the type and calls this on failure is
     * narrowed by the check alone — the throw is what makes the branch terminal
     * rather than the reader having to know this always throws.
     */
    private function wrongType(string $key, string $expected, mixed $value): never
    {
        [$file] = $this->splitKey($key);

        throw InvalidConfig::badType(
            $key,
            $expected,
            get_debug_type($value),
            $this->provenance[$key] ?? "config/{$file}.php",
        );
    }

    /** @return list<string> [filename, bare key] */
    private function splitKey(string $key): array
    {
        $dot = strpos($key, '.');
        if ($dot === false) {
            return ['app', $key];
        }
        return [substr($key, 0, $dot), substr($key, $dot + 1)];
    }
}