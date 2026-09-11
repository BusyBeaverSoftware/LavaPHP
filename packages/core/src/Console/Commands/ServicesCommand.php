<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * `lava services` — every registration with where it was wired and what it
 * actually resolved. Dependencies and dependents come from real resolution
 * traces (the boot sweep in ValidateWiring guarantees every registration has
 * one), never from static analysis.
 */
final class ServicesCommand extends AppCommand
{
    public function name(): string
    {
        return 'services';
    }

    public function summary(): string
    {
        return 'List container registrations with their wiring site and real dependencies.';
    }

    protected function emptyPayload(Args $args): array
    {
        return ['services' => []];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $traces = $app->container->traces();
        $records = [];
        $rows = [];

        foreach ($app->container->ids() as $id) {
            $record = $app->container->describe($id);
            // describe() follows aliases, so a returned id that differs from
            // the requested one means this id is an alias of that target.
            $isAlias = $record->id !== $id;
            $trace = $traces[$id] ?? null;

            $records[] = [
                'id' => $id,
                'kind' => $isAlias ? 'alias' : $record->kind->value,
                'target' => $isAlias ? $record->id : null,
                'file' => $record->file,
                'line' => $record->line,
                'class' => $isAlias ? null : $record->class,
                'dependencies' => $trace?->dependencies() ?? [],
                'dependents' => $trace?->dependents() ?? [],
            ];
            $rows[] = [
                $id,
                $isAlias ? 'alias' : $record->kind->value,
                $record->file . ':' . $record->line,
                implode(' ', $trace?->dependencies() ?? []),
            ];
        }

        $io->data('services', $records);
        $io->text((new Table(['id', 'kind', 'wired at', 'deps'], $rows))->render());

        return $io->emit($this->name());
    }
}
