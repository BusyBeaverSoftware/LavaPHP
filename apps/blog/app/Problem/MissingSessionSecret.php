<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * An app-owned problem, and one of the few that can never reach an HTTP
 * response: it is thrown from the session service's constructor, which runs
 * during boot. It exists as a `LavaProblem` rather than a plain
 * `\RuntimeException` so the failure gets the same report shape as everything
 * else — `lava check` prints the fix, and `public/index.php` renders it.
 *
 * Deliberately carries no `SourceLocation`. The fault is a missing value in
 * `config/.env`, and a file with no line — so `context` names the variable and
 * the declaration site, and inventing a line number would be a worse answer than
 * naming nothing.
 */
final class MissingSessionSecret extends LavaProblem
{
    public static function of(): self
    {
        return new self(
            'SESSION_SECRET is empty, so the session cookie would be signed with a key anyone knows.',
            "Set it in config/.env — php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' — or export it in the environment.",
            ['env_var' => 'SESSION_SECRET', 'declared_by' => 'app/Services.php'],
        );
    }

    public function code(): string
    {
        return 'missing_session_secret';
    }
}
