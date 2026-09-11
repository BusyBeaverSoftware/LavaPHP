<?php

declare(strict_types=1);

namespace Lava\View\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * A template was asked for that is not there.
 *
 * The message lists what IS there, because the two ways this happens need
 * different fixes and only the list tells them apart: a typo in the template
 * name (`tasks/show` for `task/show`) is fixed by reading the list, and an
 * empty list says something else entirely — the directory is right but nothing
 * has been written into it yet, so the fix is to create the template rather
 * than to hunt for a spelling mistake.
 *
 * Raised from `render()`, not at boot, and deliberately: a template that does
 * not exist yet is the normal state of an app being built, and boot refusing
 * to start would mean the CLI — `lava routes`, `lava check` — could not run to
 * help. Same reasoning as lava/db's `DbNotConfigured`: the diagnosis tools have
 * to work on the app that is broken.
 */
final class TemplateNotFound extends LavaProblem
{
    /**
     * @param string $template the name as looked up, extension included — {@see \Lava\View\ViewRenderer} normalizes it before asking
     * @param list<string> $available every template the loader can see, extension included
     */
    public static function of(string $template, string $directory, array $available): self
    {
        $listed = $available === []
            ? 'The directory is empty — no template exists yet.'
            : 'Available: ' . implode(', ', array_slice($available, 0, 12))
                . (count($available) > 12 ? ' … (' . count($available) . ' in total)' : '') . '.';

        return new self(
            "No template '{$template}' in {$directory}. {$listed}",
            // `$template` already carries `.twig` — the renderer normalized it
            // before looking — so the extension is NOT appended again here. The
            // sentence below says so explicitly, because a fix that reads
            // "Create …/does/not/exist.twig.twig" would send its reader to
            // create a file the loader still could not find.
            "Create {$directory}/{$template}, or call render() with one of the names above. "
            . 'A subdirectory is part of the name: a file at ' . $directory . '/tasks/show.twig is rendered as '
            . "'tasks/show'. The .twig extension is optional in render() — it is added for you when missing.",
            ['template' => $template, 'directory' => $directory, 'available' => $available],
        );
    }

    public function code(): string
    {
        return 'template_not_found';
    }
}
