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
}