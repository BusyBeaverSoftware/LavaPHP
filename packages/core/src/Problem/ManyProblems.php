<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * Several problems raised from one throw site, so one boot can report them all.
 *
 * A `LavaProblem` is one diagnosis with one fix, and that is the shape every
 * report, envelope and error page speaks. But a throw carries one value, and a
 * container factory or a module's `register()` has no other way out: the events
 * pack checks every entry of `app/Listeners.php` while the provider is built and
 * could report only the first, so a file with three mistakes took three boots to
 * learn about — against the rule that a pass collects everything it finds
 * (conventions.md, "collect all problems in one pass"; Lava Notes R3-B9).
 *
 * This is the carrier, not a problem of its own: it has no code, it never
 * reaches a renderer, and whoever catches it unpacks it into the report —
 * {@see \Lava\Core\Boot\Steps\ValidateWiring} for what a factory raised,
 * {@see \Lava\Core\Boot\Steps\WireModules} for what a module's `register()` did.
 * Anything that catches it as an ordinary throwable still gets a usable message,
 * which is why the message names the codes it carries.
 */
final class ManyProblems extends \RuntimeException
{
    /** @param non-empty-list<LavaProblem> $problems in the order they were found */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(
            count($problems) . ' problems: ' . implode(', ', array_map(
                static fn (LavaProblem $problem): string => $problem->code(),
                $problems,
            )),
            previous: $problems[0],
        );
    }

    /**
     * Raises one problem, or several together.
     *
     * A single mistake stays a single throw: nothing downstream should have to
     * unpack a carrier of one, and the reports, tests and error pages that
     * already read one problem keep reading exactly what they did.
     *
     * @param non-empty-list<LavaProblem> $problems
     */
    public static function raise(array $problems): never
    {
        throw count($problems) === 1 ? $problems[0] : new self($problems);
    }

    /** Adds every problem carried here to a report, in the order they were found. */
    public function addTo(ProblemReport $report): void
    {
        foreach ($this->problems as $problem) {
            $report->add($problem);
        }
    }
}
