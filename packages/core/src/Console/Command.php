<?php

declare(strict_types=1);

namespace Lava\Core\Console;

/**
 * One CLI command. Commands are stateless: everything they need arrives as
 * arguments to {@see run()}, so the same instance serves `lava list`, a
 * `--help` render, and a real invocation.
 *
 * A command writes its human view and its machine view as it runs (see
 * {@see IO}) and returns an {@see ExitCode}. It never branches on `--json`
 * itself — that is IO's job, and keeping it there is what stops the two
 * views from drifting.
 */
abstract class Command
{
    /** The word typed after `lava` (`routes`, `features`). */
    abstract public function name(): string;

    /** One line for `lava list`. */
    abstract public function summary(): string;

    /**
     * Flag names this command reads (without leading dashes), for `lava list`
     * and `--help`. `json` is universal but still declared, so the table is
     * the whole truth about an invocation.
     *
     * @return list<string>
     */
    public function flags(): array
    {
        return ['json'];
    }

    /** @return list<string> positional argument names, for usage text */
    public function arguments(): array
    {
        return [];
    }

    /** The pack that provides this command — `core`, or a pack name like `db`. */
    public function pack(): string
    {
        return 'core';
    }

    abstract public function run(IO $io, Args $args, string $appDir): int;

    /** The one-line invocation form shown by `--help`. */
    public function usage(): string
    {
        $parts = ['lava', $this->name()];
        foreach ($this->arguments() as $argument) {
            $parts[] = '<' . $argument . '>';
        }
        foreach ($this->flags() as $flag) {
            $parts[] = '--' . $flag;
        }
        return implode(' ', $parts);
    }
}
