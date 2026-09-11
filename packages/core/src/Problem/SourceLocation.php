<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** The user-authored artifact at fault (file + line) — distinct from the throw site inside the framework. */
final readonly class SourceLocation
{
    public function __construct(
        public string $file,
        public int $line,
    ) {
    }

    public static function of(string $file, int $line): self
    {
        return new self($file, $line);
    }

    /** @return array{file: string, line: int} */
    public function json(): array
    {
        return ['file' => $this->file, 'line' => $this->line];
    }

    public function __toString(): string
    {
        return $this->file . ':' . $this->line;
    }
}