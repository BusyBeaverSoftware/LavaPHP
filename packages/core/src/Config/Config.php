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

    /** Required accessors: a missing key or wrong type is a Problem, never a silent null. */
    public function needString(string $key): string
    {
        return $this->typed($key, 'string', is_string(...));
    }

    public function needInt(string $key): int
    {
        return $this->typed($key, 'integer', is_int(...));
    }

    public function needBool(string $key): bool
    {
        return $this->typed($key, 'boolean', is_bool(...));
    }

    /** @return array<mixed> */
    public function needArray(string $key): array
    {
        return $this->typed($key, 'array', is_array(...));
    }

    /** Optional accessors: missing key uses the given default; wrong type is still a Problem. */
    public function string(string $key, string $default): string
    {
        return $this->optional($key, $default, 'string', is_string(...));
    }

    public function int(string $key, int $default): int
    {
        return $this->optional($key, $default, 'integer', is_int(...));
    }

    public function bool(string $key, bool $default): bool
    {
        return $this->optional($key, $default, 'boolean', is_bool(...));
    }

    /** @return array<mixed> */
    public function array(string $key, array $default): array
    {
        return $this->optional($key, $default, 'array', is_array(...));
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

    /** @return array<string, mixed> every key with its provenance — feeds `lava config --json`. */
    public function json(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            $out[] = ['key' => $key, 'value' => $value, 'from_file' => $this->provenance[$key] ?? null];
        }
        return $out;
    }

    private function typed(string $key, string $expected, callable $check): mixed
    {
        if (!$this->has($key)) {
            [$file, $name] = $this->splitKey($key);
            throw new InvalidConfig(
                "Required config key '{$key}' is not set.",
                "Set '{$name}' in config/{$file}.php, or read it with a default: \$config->"
                . strtolower($expected) . "('{$key}', …).",
                ['key' => $key, 'expected' => $expected],
            );
        }
        $value = $this->values[$key];
        if (!$check($value)) {
            throw InvalidConfig::badType(
                $key,
                $expected,
                get_debug_type($value),
                $this->provenance[$key] ?? "config/{$file}.php",
            );
        }
        return $value;
    }

    private function optional(string $key, mixed $default, string $expected, callable $check): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->values[$key];
        if (!$check($value)) {
            [$file] = $this->splitKey($key);
            throw InvalidConfig::badType(
                $key,
                $expected,
                get_debug_type($value),
                $this->provenance[$key] ?? "config/{$file}.php",
            );
        }
        return $value;
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