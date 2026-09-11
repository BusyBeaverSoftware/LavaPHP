<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A config file has the wrong shape, or a typed accessor found the wrong type. */
final class InvalidConfig extends LavaProblem
{
    public function code(): string
    {
        return 'invalid_config';
    }

    /**
     * The common case: a typed accessor found the wrong type. Keys are
     * "<file>.<key>" (e.g. "app.base_url" comes from config/app.php).
     *
     * @param string $file the config file that declared the key, e.g. "config/app.php"
     */
    public static function badType(string $key, string $expected, string $got, string $file): self
    {
        [, $name] = self::splitKey($key);
        return new self(
            "Config key '{$key}' must be {$expected}, got {$got}.",
            "Fix the value of '{$name}' in {$file}.",
            ['key' => $key, 'expected' => $expected, 'got' => $got],
        );
    }

    /** @return list<string> [filename, bare key] */
    private static function splitKey(string $key): array
    {
        $dot = strpos($key, '.');
        if ($dot === false) {
            return ['app', $key];
        }
        return [substr($key, 0, $dot), substr($key, $dot + 1)];
    }

    /**
     * A config value has the right type but a value the reader cannot use.
     *
     * A negative timeout, a retry count below zero, a percentage above 100:
     * `int()` and `bool()` cannot catch these, because `-5` is an int and `0` is
     * a bool. Without this the value reaches the code that uses it and fails
     * later and somewhere else — curl rejects a negative timeout on the first
     * request, which names neither the key nor the file.
     *
     * It lives in core rather than in the pack that needed it first: the rule is
     * about config values, which is core's subject, and the fix ("fix the value
     * of 'timeout' in config/http_client.php") is text core can write without
     * knowing anything about HTTP. A pack with a numeric key gets this for free
     * instead of inventing a code of its own.
     *
     * @param string $key the full key, e.g. "http_client.timeout"
     * @param string $expected what a usable value looks like, e.g. "a positive number of seconds"
     */
    public static function outOfRange(string $key, mixed $got, string $expected, string $file): self
    {
        [, $name] = self::splitKey($key);
        return new self(
            "Config key '{$key}' is {$got}, which is out of range — it must be {$expected}.",
            "Fix the value of '{$name}' in {$file}.",
            ['key' => $key, 'got' => $got, 'expected' => $expected],
        );
    }

    /**
     * A config file did not return an array.
     *
     * @param string $file the path as the reader would type it, e.g. "config/database.php"
     */
    public static function notAnArray(string $file, string $name, string $returned): self
    {
        return new self(
            "config/{$name}.php must return an array of config values; it returned {$returned}.",
            "End the file with: return [ … ]; — its keys become '{$name}.<key>' and are read with "
            . "\$config->string('{$name}.<key>', …).",
            ['file' => $file, 'returned' => $returned],
            SourceLocation::of($file, 1),
        );
    }

    /**
     * A config file threw while it was being loaded.
     *
     * Reported against the file rather than as an unexpected failure of the
     * step that loaded it: the step is not what the reader has to change.
     */
    public static function threw(string $file, string $name, \Throwable $previous): self
    {
        return new self(
            "config/{$name}.php threw while loading: " . $previous->getMessage(),
            'A config file must only build and return an array — no queries, no service calls, no side effects '
            . 'at load time. Move that work to app/Services.php.',
            ['file' => $file, 'exception' => $previous::class],
            SourceLocation::of($file, 1),
            $previous,
        );
    }

    /**
     * A config file has a key that is not a string.
     *
     * @param mixed $key
     */
    public static function notAStringKey(string $file, string $name, mixed $key): self
    {
        return new self(
            "config/{$name}.php has a non-string key (" . get_debug_type($key) . ').',
            "Use string keys: return ['base_url' => …] — they become '{$name}.<key>'.",
            ['file' => $file, 'key' => get_debug_type($key)],
            SourceLocation::of($file, 1),
        );
    }

    /**
     * The container holds something other than what the id promises.
     *
     * A registered id is a promise about a type — `Connection::class` will
     * resolve to a Connection. app/Services.php can break that promise with
     * `value()` or a factory that returns the wrong thing, and the failure
     * then lands far from the line that caused it. Naming the id, what was
     * expected, and what is actually there puts it back next to the mistake.
     */
    public static function wrongService(string $id, string $expected, mixed $got): self
    {
        return new self(
            "The service '{$id}' should be a {$expected}, but the container resolved it to "
            . get_debug_type($got) . '.',
            "Fix the registration of '{$id}' — in the pack's module or in app/Services.php. "
            . 'The id is a promise about a type, and something registered under it does not keep it.',
            ['id' => $id, 'expected' => $expected, 'got' => get_debug_type($got)],
        );
    }
}