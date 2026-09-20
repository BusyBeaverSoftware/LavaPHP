<?php

declare(strict_types=1);

namespace Lava\Events\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;
use Lava\Events\Phase;

/**
 * One listener given two phases in `app/Listeners.php`.
 *
 * A listener named under several keys runs once, so it has one position in the
 * order — which means two phases for one id is a question the file asks and
 * cannot answer. Three spellings reach here, and the last is the likeliest:
 *
 *  - two keys disagree (`Phase::last()` under the class, bare under the
 *    interface);
 *  - one key names the listener twice with different phases;
 *  - `Phase::first($id)` sits beside a bare `$id`, because a bare id is not
 *    "unspecified" — it is the default phase, and saying both is a conflict.
 *
 * Refused at boot rather than resolved by a rule such as "the first wins",
 * because whichever rule this pack picked, the file would still read as though
 * the other were true.
 */
final class ListenerOrderConflict extends LavaProblem
{
    /**
     * @param array<string, list<string>> $byPhase phase => the keys that gave the listener that phase, in file order
     */
    public static function of(string $id, array $byPhase, string $file): self
    {
        $events = [];
        foreach ($byPhase as $keys) {
            foreach ($keys as $key) {
                if (!in_array($key, $events, true)) {
                    $events[] = $key;
                }
            }
        }

        return new self(
            "Listener '{$id}' is " . self::said($byPhase, $events) . ', and it runs once.',
            "Give it one phase. A bare id is the '" . Phase::DEFAULT . "' phase, Phase::first() runs before that phase and"
            . ' Phase::last() after it, so a listener named under several keys must carry the same phase in each.',
            ['listener' => $id, 'phases' => array_keys($byPhase), 'events' => $events],
            SourceLocation::of($file, 1),
        );
    }

    public function code(): string
    {
        return 'listener_order_conflict';
    }

    /**
     * "first and last under App\Tasks\TaskCompleted" when one key says both,
     * "last under App\Tasks\TaskCompleted and default under App\Tasks\TaskEvent"
     * when two keys disagree.
     *
     * @param array<string, list<string>> $byPhase
     * @param list<string> $events
     */
    private static function said(array $byPhase, array $events): string
    {
        $phases = array_keys($byPhase);
        if (count($events) === 1) {
            return implode(' and ', $phases) . ' under ' . $events[0];
        }

        $clauses = [];
        foreach ($byPhase as $phase => $keys) {
            $clauses[] = $phase . ' under ' . implode(' and ', $keys);
        }

        return implode(', and ', $clauses);
    }
}
