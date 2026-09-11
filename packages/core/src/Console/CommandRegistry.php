<?php

declare(strict_types=1);

namespace Lava\Core\Console;

use Lava\Core\Console\Commands\AboutCommand;
use Lava\Core\Console\Commands\CheckCommand;
use Lava\Core\Console\Commands\ConfigCommand;
use Lava\Core\Console\Commands\DescribeCommand;
use Lava\Core\Console\Commands\EnvCommand;
use Lava\Core\Console\Commands\FeaturesCommand;
use Lava\Core\Console\Commands\ListCommand;
use Lava\Core\Console\Commands\RoutesCommand;
use Lava\Core\Console\Commands\ServeCommand;
use Lava\Core\Console\Commands\ServicesCommand;
use Lava\Core\Console\Commands\TestCommand;
use Lava\Core\Problem\DuplicateCommand;

/**
 * The commands this process can run, keyed by name.
 *
 * A name is registered once. A second command claiming it throws
 * {@see DuplicateCommand} rather than overwriting: silent shadowing is the
 * class of magic this framework bans everywhere else, and a pack quietly
 * taking `routes` from core would break an agent that had already learned it.
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

    /** @throws DuplicateCommand when the name is already taken */
    public function add(Command $command): void
    {
        $existing = $this->commands[$command->name()] ?? null;
        if ($existing !== null) {
            throw DuplicateCommand::of($command->name(), $existing, $command);
        }
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
     * registry when their module registers — see {@see \Lava\Core\Modules\ProvidesCommands}
     * and the RegisterCommands boot step.
     *
     * Registration order is the order `lava list` prints, so it is the order a
     * human reads: orient (about), verify (check), then the tables, then the
     * runner, then the lookup. `list` goes last because it is the index, not an
     * entry.
     */
    public static function core(): self
    {
        $registry = new self();
        $registry->add(new AboutCommand());
        $registry->add(new CheckCommand());
        $registry->add(new RoutesCommand());
        $registry->add(new ServicesCommand());
        $registry->add(new FeaturesCommand());
        $registry->add(new ConfigCommand());
        $registry->add(new EnvCommand());
        $registry->add(new TestCommand());
        $registry->add(new ServeCommand());
        $registry->add(new DescribeCommand());
        $registry->add(new ListCommand($registry));
        return $registry;
    }
}
