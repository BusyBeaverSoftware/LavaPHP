<?php

declare(strict_types=1);

namespace Lava\Core\Modules;

use Lava\Core\Boot\App;

/**
 * A module that adds facts of its own to {@see \Lava\Core\Boot\RuntimeFacts},
 * and so to `lava about` and any page that reads the service.
 *
 * The optional-capability idiom again, as {@see ProvidesMapSection} and
 * {@see ProvidesCommands} are: the facts ask each enabled module with
 * instanceof, in app/Modules.php order, and a module that implements nothing
 * adds nothing. It is for the state a pack alone can see — how many migrations
 * are waiting, say — which is what someone wants when an app is misbehaving and
 * no other command has answered.
 *
 * **Asked when the facts are read, never at boot.** `RuntimeFacts` is built
 * while the container is validated, where a constructor does no I/O
 * ({@see \Lava\Core\Boot\Steps\ValidateWiring}), and a fact worth reporting
 * often needs exactly the I/O that rule forbids: pending migrations need the
 * database. So the call happens when a caller asks for the facts, with the
 * booted app in hand, and a pack may query there. It costs nothing until asked,
 * which is what keeps it off the boot path.
 *
 * The keys are the pack's own — nothing in core reads them, and `lava about`
 * prints them as they come. One key is a convention rather than a rule:
 * `error`, for a fact that could not be computed. `about` is the command
 * someone runs when nothing else works, so a pack that threw would take the
 * whole report down with it; `RuntimeFacts` catches a throwing pack and records
 * `error` itself, and a pack that would rather explain can set it too.
 */
interface ProvidesFacts
{
    /**
     * The pack's facts for a booted app: JSON-ready scalars, lists and maps.
     *
     * @return array<string, mixed>
     */
    public function facts(App $app): array;
}
