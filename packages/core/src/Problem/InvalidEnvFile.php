<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A line in config/.env is not KEY=VALUE. */
final class InvalidEnvFile extends LavaProblem
{
    public function code(): string
    {
        return 'invalid_env_file';
    }

    public static function of(string $file, int $lineNumber, string $rawLine): self
    {
        return new self(
            "{$file} line {$lineNumber} is not KEY=VALUE.",
            "Write env lines as KEY=VALUE. Quotes are optional; there is no interpolation; comments start with #.",
            ['file' => $file, 'line' => $lineNumber, 'content' => trim($rawLine)],
            SourceLocation::of($file, $lineNumber),
        );
    }
}