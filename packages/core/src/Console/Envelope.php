<?php

declare(strict_types=1);

namespace Lava\Core\Console;

/**
 * The `--json` wire contract, in one place.
 *
 *   {"schema":"lava.<cmd>/1","command":"<cmd>","status":"ok|failed",
 *    "data":{…},"problems":[<problem object>]}
 *
 * `schema` is versioned per command (`lava.routes/1`) so a command can evolve
 * its `data` shape with a bump while the others stay put. `problems` always
 * uses the same problem object as boot reports and HTTP bodies — an agent
 * parses one shape everywhere. Compact, single-line: agents parse JSON, and
 * the pretty renderer belongs to the text view.
 */
final class Envelope
{
    public const VERSION = '1';

    public static function schema(string $command): string
    {
        return 'lava.' . $command . '/' . self::VERSION;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $problems
     * @return array<string, mixed>
     */
    public static function of(string $command, string $status, array $data, array $problems = []): array
    {
        return [
            'schema' => self::schema($command),
            'command' => $command,
            'status' => $status,
            'data' => $data,
            'problems' => $problems,
        ];
    }

    /** @param array<string, mixed> $envelope */
    public static function encode(array $envelope): string
    {
        return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
