<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * A task id that matches no row.
 *
 * An app-owned problem, and the reason the framework's code registry has no
 * entry for it: `docs/problem-codes.md` catalogues the codes the *framework*
 * ships, one per class under `Lava\Core\Problem\` or a pack's `Problem\`
 * directory. An app's own codes live in the app's own namespace and are the
 * app's to document — nothing in core or in a pack could know that `tasks` is
 * a table this app has.
 *
 * The three things a problem carries are the three an agent needs in one round
 * trip, and none of them is optional here: WHAT failed (`No task with id 7.`),
 * the failing INPUT (`context.id`), and the FIX — an imperative that names the
 * exact call which would have returned the id.
 */
final class TaskNotFound extends LavaProblem
{
    public function code(): string
    {
        return 'task_not_found';
    }

    /**
     * The caller asked for something that is not there. That is their problem
     * to fix by asking differently, not a fault in this app — which is the
     * whole distinction between a 404 and a 500.
     */
    public function httpStatus(): int
    {
        return 404;
    }

    public static function of(int $id): self
    {
        return new self(
            "No task with id {$id}.",
            'Run: lava app:stats --json, or GET /tasks, to list the ids that exist.',
            ['id' => $id],
        );
    }
}
