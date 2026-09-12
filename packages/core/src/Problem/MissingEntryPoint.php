<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * `lava serve` was asked to serve an app that has no `public/index.php`.
 *
 * `php -S` needs a front controller; without one the server either 404s
 * everything or serves the directory listing, which looks like a working
 * server with a broken app — the worst possible diagnosis. The canonical
 * entry point is shipped in the `lavaphp/app` skeleton, so the fix is a copy,
 * not a rewrite.
 */
final class MissingEntryPoint extends LavaProblem
{
    public static function in(string $appDir): self
    {
        return new self(
            "'{$appDir}' has no public/index.php, so there is nothing to serve.",
            'Create public/index.php — copy the canonical one from the lavaphp/app skeleton '
                . '(require the autoloader, then Kernel::boot() and handle the request).',
            ['app_dir' => $appDir, 'expected' => 'public/index.php'],
        );
    }

    public function code(): string
    {
        return 'missing_entry_point';
    }
}
