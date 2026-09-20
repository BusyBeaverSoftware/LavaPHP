<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Config\Secrets;

/**
 * A line in config/.env is not KEY=VALUE.
 *
 * The line's VALUE never reaches the report. A `.env` file is where an app's
 * credentials live, and the two shapes that fail this parser most often are
 * `export API_KEY=…` (the key gains a space) and `stripe_secret_key=…` (a
 * lowercase key) — both of which carry a live secret on the same line. This is
 * a boot problem, so it surfaces in every command's report, on the diagnostics
 * page, and in whatever CI log runs `lava check --strict`; a well-formed entry
 * with the same name is redacted, and the malformed one used to be the only
 * value that escaped.
 *
 * The key half is enough to fix the line, and the file and line number are in
 * the problem already.
 */
final class InvalidEnvFile extends LavaProblem
{
    /**
     * Longer than any name an operator writes, and short enough that a pasted
     * credential cannot hide in the key half — base64 carries `=` padding, so
     * "everything before the first `=`" can be the whole secret.
     */
    private const MAX_KEY = 48;

    public function code(): string
    {
        return 'invalid_env_file';
    }

    public static function of(string $file, int $lineNumber, string $rawLine): self
    {
        return new self(
            "{$file} line {$lineNumber} is not KEY=VALUE.",
            "Write env lines as KEY=VALUE. Quotes are optional; there is no interpolation; comments start with #.",
            ['file' => $file, 'line' => $lineNumber, 'content' => self::keyOf($rawLine)],
            SourceLocation::of($file, $lineNumber),
        );
    }

    /**
     * The line as it is safe to print: its key, then a redaction marker.
     *
     * A line with no `=` gets no key half at all, because a line that is only a
     * value — a token pasted on its own — is exactly the shape worth
     * withholding. So is a key long enough to be a credential, or one carrying
     * control characters, which have no business in a name and could forge a
     * row in a terminal.
     */
    private static function keyOf(string $rawLine): string
    {
        $line = trim($rawLine);
        $equals = strpos($line, '=');
        if ($equals === false) {
            return Secrets::redacted();
        }

        $key = substr($line, 0, $equals);
        if ($key === '' || strlen($key) > self::MAX_KEY || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            return Secrets::redacted();
        }

        return $key . '=' . Secrets::redacted();
    }
}
