<?php

declare(strict_types=1);

namespace Lava\View\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * The views feature is on, but the directory it renders from is not there.
 *
 * Raised at boot, from `register()`, unlike the other two problems in this
 * pack. The difference is what the reader can do about it: a missing template
 * is normal while an app is being written, but a missing template *directory*
 * means the pack has nothing to render from at all, and every render would fail
 * identically. Failing at boot turns N identical request-time errors into one
 * message with the path and the config key to fix.
 *
 * It also has to be raised here rather than left to Twig. `FilesystemLoader`
 * throws `LoaderError` on a missing path, and that would surface through
 * `ValidateWiring` as `unexpected_failure` — "a Twig error escaped during
 * wiring" — with no mention of which key set the path or how to change it.
 */
final class ViewDirMissing extends LavaProblem
{
    public static function of(string $path, string $configKey, string $appDir): self
    {
        return new self(
            "lavaphp/view renders from {$path}, which is not a directory.",
            "Create it — mkdir -p {$path} — or point the pack somewhere else: add 'path' => '…' to "
            . "config/view.php, where a relative value is resolved against {$appDir}.",
            ['path' => $path, 'config_key' => $configKey, 'app_dir' => $appDir],
        );
    }

    public function code(): string
    {
        return 'view_dir_missing';
    }
}
