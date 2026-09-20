<?php

declare(strict_types=1);

namespace App\EventsFixture;

/**
 * Listed with `Phase::last()`, so it writes after every default-phase listener
 * however the entries are ordered.
 */
final readonly class JournalAudit
{
    public function __construct(private Journal $journal)
    {
    }

    public function __invoke(TaskCompleted $event): void
    {
        $this->journal->lines[] = "audited #{$event->id}";
    }
}
