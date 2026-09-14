<?php

declare(strict_types=1);

namespace Lava\Core\Modules;

use Lava\Core\Boot\App;
use Lava\Core\Map\MapSection;

/**
 * A module that adds a section of its own to the app's map, AGENTS.md.
 *
 * The optional-capability idiom again, as {@see ProvidesRoutes} and
 * {@see ProvidesCommands} are: `lava map` asks each enabled module with
 * instanceof, in app/Modules.php order, and a module that implements nothing
 * adds nothing. It is for facts that only the pack holds and an agent still
 * needs before changing the app — which listeners an event reaches, say —
 * because the map is otherwise compiled from core's four registries alone.
 *
 * The section is part of the map's fingerprint, so a change to what it lists
 * makes a committed map stale, exactly as a new route does.
 */
interface ProvidesMapSection
{
    /** The pack's section, built from the booted app, or null when it has nothing to list. */
    public function mapSection(App $app): ?MapSection;
}
