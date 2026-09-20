<?php

declare(strict_types=1);

namespace Lava\Events\Console;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\Commands\AppCommand;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;
use Lava\Events\ListenerMap;
use Lava\Events\Phase;

/**
 * `lava events` — every event `app/Listeners.php` names and the listeners it
 * reaches, in the order they run: the registry as boot read it, so a
 * listener that is not here does not run.
 */
final class EventsCommand extends AppCommand
{
    public function name(): string
    {
        return 'events';
    }

    public function summary(): string
    {
        return 'List each event in app/Listeners.php and the listeners it reaches, in order.';
    }

    public function pack(): string
    {
        return 'events';
    }

    public function emptyPayload(Args $args): array
    {
        // `phases` is the pack's own vocabulary rather than the app's, so it is
        // honest even here: a boot that failed has no registry to read, and the
        // three names are still what an entry may say.
        return ['file' => null, 'phases' => Phase::ALL, 'events' => []];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $map = $app->container->get(ListenerMap::class);
        if (!$map instanceof ListenerMap) {
            throw new \LogicException('lavaphp/events registers ListenerMap; something else holds that id.');
        }

        $events = [];
        $rows = [];
        // The order an event of each key RUNS, not the order the file lists:
        // a phase moves a listener across entries, and a reader that had to
        // redo that sort would be reading a different registry from dispatch.
        foreach ($map->ordered() as $event => $listeners) {
            $events[] = ['event' => $event, 'listeners' => $listeners];
            $rows[] = [$event, implode(', ', array_map(Phase::label(...), $listeners))];
        }

        $io->data('file', $map->file === null ? null : ListenerMap::FILE);
        $io->data('phases', Phase::ALL);
        $io->data('events', $events);
        $io->text($map->file === null
            ? "No app/Listeners.php: no event reaches a listener.\n"
            : (new Table(['event', 'listeners'], $rows))->render());

        return $io->emit($this->name());
    }
}
