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
use Lava\Core\Console\Commands\MapCommand;
use Lava\Core\Console\Commands\RoutesCommand;
use Lava\Core\Console\Commands\ServeCommand;
use Lava\Core\Console\Commands\ServicesCommand;
use Lava\Core\Console\Commands\TestCommand;
use Lava\Core\Problem\DuplicateCommand;
use Lava\Core\Problem\InvalidCommandName;

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
    /**
     * What a command may be called: lowercase words of letters and digits, each
     * starting with a letter, joined by colons — `routes`, `db:status`,
     * `blog:publish`. Narrow because the name becomes the envelope's contract id
     * (`lava.db.status/1`), whose pattern admits nothing else.
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9]*(?::[a-z][a-z0-9]*)*$/';

    /** @var array<string, Command> in registration order */
    private array $commands = [];

    /** @var array<string, string> name => who registered it, for commands added through {@see addingFor()} */
    private array $origins = [];

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

    /**
     * A warning for every registered command whose name cannot be a contract id
     * (see {@see NAME_PATTERN}).
     *
     * Reported, not refused. The RegisterCommands step asks for these on every
     * boot — a web request's boot included — and a name that breaks `--json`
     * envelopes must not stop a website from serving. As a warning it is still in
     * `lava check`, and `--strict` fails on it.
     *
     * @return list<InvalidCommandName>
     */
    public function nameProblems(): array
    {
        $problems = [];
        // A suggestion is offered once: two names with the same nearest name
        // (`Report:Daily`, `report daily`) would collide the moment both renames
        // were made.
        $taken = array_map(strval(...), array_keys($this->commands));
        foreach ($this->commands as $command) {
            if (preg_match(self::NAME_PATTERN, $command->name()) !== 1) {
                $problem = InvalidCommandName::of($command, $this->origin($command), $taken);
                $problems[] = $problem;
                if (is_string($problem->context['suggestion'] ?? null)) {
                    $taken[] = $problem->context['suggestion'];
                }
            }
        }

        return $problems;
    }

    /**
     * Records every command `$register` adds as registered by `$origin` —
     * `app` for app/Commands.php. Needed because `pack()` defaults to `core`, so
     * without it an app command that never overrode it reads as core's.
     *
     * @param \Closure(self): void $register
     */
    public function addingFor(string $origin, \Closure $register): void
    {
        $before = $this->commands;
        try {
            $register($this);
        } finally {
            foreach (array_diff_key($this->commands, $before) as $name => $command) {
                $this->origins[$name] = $origin;
            }
        }
    }

    /** Who provides a command: the pack it names, or else who registered it. */
    public function origin(Command $command): string
    {
        $pack = $command->pack();

        return $pack !== 'core' ? $pack : ($this->origins[$command->name()] ?? 'core');
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
        $registry->add(new MapCommand());
        $registry->add(new TestCommand());
        $registry->add(new ServeCommand());
        $registry->add(new DescribeCommand());
        $registry->add(new ListCommand($registry));
        return $registry;
    }
}
