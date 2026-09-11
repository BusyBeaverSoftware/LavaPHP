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
     * Flags the KERNEL reads on every command's behalf, so they are accepted
     * everywhere and declared by none.
     *
     * The rule that decides membership is mechanical, not a taste: a flag is
     * universal when the kernel is what reads it. `json` and `quiet` build the
     * writer (`IO::standard`), `help` is answered by the dispatcher before the
     * command is consulted, and `env` selects the boot — so none of the four
     * needs a command's cooperation, and requiring every command to declare
     * them would be describing the kernel's work as the command's.
     *
     * This is also what an undeclared flag is checked against: an invocation
     * may carry a flag only if the command declares it or it is here. Without
     * the split, `--quiet` on `lava routes` could not be rejected (it works)
     * and a typo could not be caught (it is silently ignored).
     *
     * `json` is still declared by commands, for `lava list`'s table: that
     * column describes what the command makes of its arguments, and a reader
     * scanning it should not have to know this list to know `--json` works.
     *
     * @var list<string>
     */
    public const UNIVERSAL_FLAGS = ['json', 'quiet', 'help', 'env'];

    /**
     * Flag names this command reads (without leading dashes), for `lava list`
     * and `--help` — and the list the kernel enforces: every flag in argv must
     * be declared here or be one of {@see UNIVERSAL_FLAGS}. `json` is
     * universal but still declared, so the table is the whole truth about an
     * invocation.
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

    /**
     * This command's payload keys with nothing — or nothing true — in them.
     *
     * The ONE declaration of what `lava.<cmd>/1`'s `data` holds, and public
     * because two callers need it: the command itself, seeding before anything
     * can fail, and the kernel, which seeds before dispatching so that the
     * envelopes it emits on its OWN behalf — `--help`, and an invocation
     * rejected for an undeclared flag — carry the same keys as a real run.
     * Both call this method, so there is no second copy of the shape to drift.
     *
     * `$args` is passed because the shape can depend on the subcommand, and on
     * how the invocation was typed: `lava features` lists flags while
     * `lava features resolve` reports one, and each must hand a `--json`
     * consumer its own keys even when nothing ran.
     *
     * @return array<string, mixed>
     */
    public function emptyPayload(Args $args): array
    {
        return [];
    }
}
