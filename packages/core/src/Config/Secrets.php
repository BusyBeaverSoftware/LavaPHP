<?php

declare(strict_types=1);

namespace Lava\Core\Config;

/**
 * The name-based secret heuristic, used ONLY where nothing declared the value:
 * config keys (plain strings with no declaration site) and `.env` entries no
 * EnvVar claims.
 *
 * A declaration always wins — an EnvVar built with secret: false is shown even
 * if its name matches, because explicit beats heuristic. This is a redaction
 * safety net, not a policy engine: it errs toward hiding a value an operator
 * might not want in a log, and `--reveal` is always one flag away.
 *
 * @internal redaction for problem context and env output
 */
final class Secrets
{
    /**
     * Matched against each underscore-separated word of the name, so
     * 'api_key' and 'DB_PASSWORD' match while 'monkey' and 'base_url' do not.
     */
    private const WORDS = [
        'password', 'passwd', 'secret', 'token', 'key', 'credential',
        'credentials', 'dsn', 'database_url', 'private',
    ];

    /**
     * Whether a value is redacted: the declaration if there is one, the name
     * heuristic if there is not.
     *
     * Every view that prints an env var asks this, and asks it HERE, because
     * the two that asked it separately drifted: `lava env` fell back to the
     * heuristic for a bare `.env` entry and `lava describe` did not, so one
     * command redacted an undeclared DB_PASSWORD while the other printed it
     * and reported `secret: false`.
     */
    public static function isSecret(?EnvVar $var, string $name): bool
    {
        return $var !== null ? $var->secret : self::looksSecret($name);
    }

    public static function looksSecret(string $name): bool
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($name)) ?: [];
        foreach ($words as $word) {
            if (in_array($word, self::WORDS, true)) {
                return true;
            }
        }
        return false;
    }

    /** What a redacted value looks like in every view — one constant, so text and JSON agree. */
    public static function redacted(): string
    {
        return '<redacted>';
    }
}
