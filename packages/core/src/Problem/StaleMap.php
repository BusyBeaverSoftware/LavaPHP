<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * `AGENTS.md` is not an accurate description of this app.
 *
 * Severity is Warn, and that is the whole reason this problem exists rather than
 * a fatal: an out-of-date map makes an agent's reading of the app wrong, but the
 * app itself is fine — every route still matches, every service still resolves.
 * Failing `lava check` for it would make a documentation chore into a broken
 * build, and `--strict` is where a team that wants that says so.
 *
 * One code, three situations, distinguished by `context.why`:
 *
 *  - `missing`    — there is no AGENTS.md, so an agent has no map at all;
 *  - `stale`      — the file's marker records a hash the app no longer matches;
 *  - `unwritable` — the write itself failed (a read-only checkout).
 *
 * `unwritable` rides on this code rather than a code of its own because the
 * CODE means "the map is not accurate", which a failed write leaves exactly
 * true — only the fix differs, and `why` is what tells them apart. The problem
 * registry is a public contract (docs/problem-codes.md); an environment
 * condition that no consumer would branch on differently is not worth growing
 * it by one.
 */
final class StaleMap extends LavaProblem
{
    public static function missing(string $path): self
    {
        return new self(
            "AGENTS.md is missing from this app, so an agent working in it has no map of its routes, services, flags, or environment.",
            "Run: lava map — write AGENTS.md from the app's own registries.",
            ['path' => $path, 'why' => 'missing'],
        );
    }

    public static function stale(string $path, string $found, string $expected): self
    {
        return new self(
            "AGENTS.md describes an earlier version of this app: it records hash {$found}, and the app now hashes to {$expected}.",
            "Run: lava map — regenerate AGENTS.md from the app's own registries.",
            ['path' => $path, 'why' => 'stale', 'found' => $found, 'expected' => $expected],
            // Line 1 is where the marker lives, which is the line that is wrong.
            SourceLocation::of($path, 1),
        );
    }

    public static function unwritable(string $path, string $directory): self
    {
        return new self(
            "AGENTS.md could not be written: {$path} is not writable.",
            "Make the app directory writable (chmod u+w {$directory}) and run: lava map.",
            ['path' => $path, 'why' => 'unwritable', 'directory' => $directory],
        );
    }

    public function code(): string
    {
        return 'stale_map';
    }

    public function severity(): Severity
    {
        return Severity::Warn;
    }
}
