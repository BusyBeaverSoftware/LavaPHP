<?php

declare(strict_types=1);

namespace Lava\Core\Console;

use Lava\Core\Console\Commands\AboutCommand;
use Lava\Core\Console\Commands\ConfigCommand;
use Lava\Core\Console\Commands\DescribeCommand;
use Lava\Core\Console\Commands\EnvCommand;
use Lava\Core\Console\Commands\FeaturesCommand;
use Lava\Core\Console\Commands\ListCommand;
use Lava\Core\Console\Commands\RoutesCommand;
use Lava\Core\Console\Commands\ServicesCommand;

/**
 * The commands this process can run, keyed by name.
 *
 * A name is registered once — a second command claiming it would be silent
 * shadowing, the class of magic this framework bans everywhere else, so
 * {@see add()} overwriting is deliberate and only used by the builder.
 */
final class CommandRegistry
{
    /** @var array<string, Command> in registration order */
    private array $commands = [];

    /** @param list<Command> $commands */
    public function __construct(array $commands = [])
    {
        foreach ($commands as $command) {
            $this->add($command);
        }
    }

    public function add(Command $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function get(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    /** @return list<Command> in registration order */
    public function all(): array
    {
        return array_values($this->commands);
    }

    /** The closest command name to a typo, or null when nothing is close. */
    public function nearest(string $name): ?string
    {
        $nearest = null;
        $distance = PHP_INT_MAX;
        foreach (array_keys($this->commands) as $candidate) {
            $candidateDistance = levenshtein($name, $candidate);
            if ($candidateDistance < $distance) {
                $distance = $candidateDistance;
                $nearest = $candidate;
            }
        }
        // Two edits is a typo; anything further is a different word, and a
        // wrong "did you mean" costs more than none.
        return $distance <= 2 ? $nearest : null;
    }

    /**
     * The commands every LavaPHP app has. Pack commands (db:*, …) join the
     * registry when their module registers, once packs land.
     *
     * Registration order is the order `lava list` prints, so it is the order a
     * human reads: orient (about), then the four tables, then the lookup.
     * `list` goes last because it is the index, not an entry.
     */
    public static function core(): self
    {
        $registry = new self();
        $registry->add(new AboutCommand());
        $registry->add(new RoutesCommand());
        $registry->add(new ServicesCommand());
        $registry->add(new FeaturesCommand());
        $registry->add(new ConfigCommand());
        $registry->add(new EnvCommand());
        // describe() needs the registry to answer for commands, so it is
        // constructed against the same instance it is being added to.
        $registry->add(new DescribeCommand($registry));
        $registry->add(new ListCommand($registry));
        return $registry;
    }
}
