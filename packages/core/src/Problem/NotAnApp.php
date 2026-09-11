<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * The directory booted contains none of the three things that make a
 * directory a LavaPHP app: `app/`, `config/`, or `public/index.php`.
 *
 * This exists because booting one anyway SUCCEEDS: every artifact in
 * conventions.md is optional, so a random directory produces an app with no
 * routes, no services, and no config. `lava routes` there would answer
 * `status: ok, routes: []` — a flat lie that reads as "your app has no
 * routes" when the truth is "there is no app here". An agent debugging a
 * missing route would search the wrong problem entirely.
 *
 * Severity is Fatal: nothing downstream of this is meaningful.
 */
final class NotAnApp extends LavaProblem
{
    /**
     * @param list<string> $expected the artifacts checked for, in the order checked
     */
    public static function at(string $appDir, array $expected): self
    {
        return new self(
            "'{$appDir}' is not a LavaPHP app: it contains no app/ directory, "
                . 'no config/ directory, and no public/index.php.',
            "Run lava from your app's root directory (the one holding app/ or config/), "
                . 'or create the layout: config/app.php and app/Routes.php.',
            ['app_dir' => $appDir, 'expected' => $expected],
        );
    }

    public function code(): string
    {
        return 'not_an_app';
    }
}
