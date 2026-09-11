<?php

declare(strict_types=1);

namespace Lava\Core\Config;

use Lava\Core\Problem\InvalidEnvFile;
use Lava\Core\Problem\ProblemReport;

/**
 * Parser for config/.env. The grammar is deliberately tiny: KEY=VALUE lines,
 * optional surrounding quotes, # comment lines, nothing else. No interpolation,
 * no escapes, no inline comments — what you write is what you get.
 *
 * DotEnv never overrides real environment variables; that policy belongs to
 * the boot step, not the parser.
 */
final class DotEnv
{
    private const KEY_PATTERN = '/^[A-Z_][A-Z0-9_]*$/';

    /**
     * Parses the file, appending a problem (and skipping) each malformed line.
     *
     * @return array<string, string> the valid KEY => VALUE pairs
     */
    public static function load(string $file, ProblemReport $report): array
    {
        if (!is_file($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $values = [];
        foreach ($lines as $number => $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $equals = strpos($line, '=');
            if ($equals === false) {
                $report->add(InvalidEnvFile::of($file, $number + 1, $raw));
                continue;
            }
            $key = trim(substr($line, 0, $equals));
            $value = self::unquote(trim(substr($line, $equals + 1)));
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                $report->add(InvalidEnvFile::of($file, $number + 1, $raw));
                continue;
            }
            $values[$key] = $value;
        }
        return $values;
    }

    private static function unquote(string $value): string
    {
        $len = strlen($value);
        if ($len >= 2
            && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))
        ) {
            return substr($value, 1, -1);
        }
        return $value;
    }
}