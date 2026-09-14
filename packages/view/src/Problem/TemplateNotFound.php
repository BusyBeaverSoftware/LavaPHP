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
 * help. Same reasoning as lavaphp/db's `DbNotConfigured`: the diagnosis tools have
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

    /**
     * The same problem for a `@namespace/…` name, which Twig looks up in that
     * namespace's directories and nowhere else — so those are the ones named.
     *
     * @param string $relative the name inside the namespace — `layout.twig` for `@theme/layout.twig`
     * @param list<string> $paths the namespace's directories, as Twig's loader holds them
     * @param list<string> $available every template in them, as `@namespace/…` names
     * @param list<string> $declared the namespaces that do have directories, for a name that has none
     */
    public static function inNamespace(string $template, string $namespace, string $relative, array $paths, array $available, array $declared = []): self
    {
        // A namespace is declared in config/view.php (entry 287), so that is
        // where the fix sends the reader, with the names that are declared: a
        // typo is fixed by reading them (Lava Notes, R3-B14).
        if ($paths === []) {
            return new self(
                "No template '{$template}': no directory is registered for the Twig namespace '@{$namespace}'. "
                . ($declared === [] ? 'No namespace is declared.' : 'Declared: @' . implode(', @', $declared) . '.'),
                "Declare it in config/view.php under 'namespaces' (view.namespaces), 'namespaces' => ['{$namespace}' => 'path/to/templates'], "
                . 'or correct the namespace in the name.',
                ['template' => $template, 'namespace' => $namespace, 'directories' => [], 'available' => [], 'declared' => $declared],
            );
        }

        $listed = $available === []
            ? "The namespace's directories hold no template yet."
            : 'Available: ' . implode(', ', array_slice($available, 0, 12))
                . (count($available) > 12 ? ' … (' . count($available) . ' in total)' : '') . '.';

        return new self(
            "No template '{$template}' in " . implode(', ', $paths) . ". {$listed}",
            "Create {$paths[0]}/{$relative}, or call render() with one of the names above.",
            [
                'template' => $template,
                'namespace' => $namespace,
                'directory' => $paths[0],
                'directories' => $paths,
                'available' => $available,
            ],
        );
    }

    public function code(): string
    {
        return 'template_not_found';
    }
}
