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

    /**
     * The contract name for a command: `routes` → `lava.routes/1`,
     * `db:status` → `lava.db.status/1`.
     *
     * The colon in a pack's namespaced command becomes a dot, and that is a
     * deliberate translation rather than an accident of string handling. The
     * schema name is a FILE name — `docs/schemas/<schema>.json` — and a colon
     * is illegal in a path on Windows, so `docs/schemas/lava.db:status/1.json`
     * would make the repository uncheckoutable there. It also keeps the name
     * inside the envelope's own `schema` pattern, which admits letters, digits
     * and dots.
     *
     * `command` in the envelope is NOT translated: it stays the string a
     * caller types, because that is what an agent passes back on the command
     * line. Only the contract's name is filename-safe.
     */
    public static function schema(string $command): string
    {
        return 'lava.' . str_replace(':', '.', $command) . '/' . self::VERSION;
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
