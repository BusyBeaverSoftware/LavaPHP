<?php

declare(strict_types=1);

namespace Lava\Core\Map;

/**
 * AGENTS.md as a file on disk: the marker line, the read, the write, and the
 * freshness verdict.
 *
 * **The marker is the first line, and it is anchored there.** The file begins
 * with `<!-- lava:map hash=<16 hex> -->` and the check matches `\A`, not "the
 * first twenty lines". Scanning a window would make the verdict depend on where
 * a reader put a blank line, and it would have to reason about whether a hash
 * inside a fenced example is the marker — questions with no good answers. One
 * anchored line is unambiguous: it is there, or the file was not written by this
 * version of `lava map` and is stale by definition.
 *
 * **The hash covers the app's FACTS, not this file's bytes.** Regenerating a
 * document whose wording changed must not report every app in the world as
 * stale, because nothing about those apps changed. See {@see ProjectMap::fingerprint()}.
 */
final class MapDocument
{
    public const FILENAME = 'AGENTS.md';

    /**
     * `\A` anchors to the start of the file; internal whitespace is tolerated so
     * a reformatting tool that reflows the comment does not silently orphan the
     * hash, while the hash itself must be exactly the 16 hex characters this
     * version writes.
     */
    private const MARKER = '/\A<!--\s*lava:map\s+hash=([0-9a-f]{16})\s*-->/';

    private function __construct(
        public readonly string $path,
        private readonly ?string $contents,
    ) {
    }

    /** The document at an app root. A file that cannot be read reads as absent. */
    public static function at(string $appDir): self
    {
        $path = rtrim($appDir, '/') . '/' . self::FILENAME;
        $raw = is_file($path) ? file_get_contents($path) : false;

        return new self($path, is_string($raw) ? $raw : null);
    }

    public function exists(): bool
    {
        return $this->contents !== null;
    }

    /** The hash the file claims, or null when it is absent or carries no marker. */
    public function hash(): ?string
    {
        if ($this->contents === null) {
            return null;
        }

        return preg_match(self::MARKER, $this->contents, $matches) === 1 ? $matches[1] : null;
    }

    public function isFresh(string $fingerprint): bool
    {
        return $this->hash() === $fingerprint;
    }

    /** The bytes this app's AGENTS.md should hold: the marker, then the document. */
    public function render(ProjectMap $map): string
    {
        return self::marker($map->fingerprint()) . $map->markdown();
    }

    /**
     * Writes the document. False means the write did not happen — an unwritable
     * directory, a full disk — which the caller must report rather than treat as
     * a success: `lava map`'s one job is producing this file.
     *
     * The `@` is deliberate. `file_put_contents` also emits a PHP warning on
     * failure, and this return value is the same news in a form the caller can
     * act on; letting the warning through would put a raw PHP diagnostic on
     * stderr alongside the problem that actually names the fix.
     */
    public function write(ProjectMap $map): bool
    {
        return @file_put_contents($this->path, $this->render($map)) !== false;
    }

    public static function marker(string $fingerprint): string
    {
        return "<!-- lava:map hash={$fingerprint} -->\n";
    }
}
