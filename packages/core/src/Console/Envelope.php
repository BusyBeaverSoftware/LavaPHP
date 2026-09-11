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
     * Commands whose contract has moved past {@see VERSION}, and the version
     * they are on now.
     *
     * The frozen-`/N` rule (docs/conventions.md) says a breaking payload change
     * means a NEW `/N`, never an edit to the existing one — so the version is a
     * per-command fact, and this is the one place it lives.
     *
     * Two commands are on `/2`, for the same reason worded two ways: a consumer
     * that read `/1` would mishandle a value `/2` can carry. `lava.check/1`
     * promised that `sections[].name` was one of eight names; M7 added `map`,
     * which a consumer switching exhaustively on that enum would not have
     * handled. `lava.map/1` promised `path` and `fingerprint` were strings, but
     * both are facts about the APP — so an invocation that never read one (a
     * flag the command does not declare, `--help`, a boot that failed) had no
     * honest string to put there, and `/1` described a payload the command has
     * never emitted on those paths. Widening is therefore breaking by this
     * project's rule, and the numeral is how the consumer finds out.
     *
     * Nothing emits a superseded version — pre-0.1.0 there is no release to
     * keep compatible — so a bump here also means deleting the old schema file,
     * and `JsonSchemaTest` derives the expected file list from this method. That
     * is what makes a forgotten entry fail the build instead of going unnoticed.
     *
     * @var array<string, string>
     */
    private const VERSIONS = ['check' => '2', 'map' => '2'];

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
        return 'lava.' . str_replace(':', '.', $command) . '/' . (self::VERSIONS[$command] ?? self::VERSION);
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
