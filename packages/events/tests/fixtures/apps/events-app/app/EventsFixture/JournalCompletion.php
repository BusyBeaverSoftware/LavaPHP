<?php

declare(strict_types=1);

namespace App\EventsFixture;

final readonly class JournalCompletion
{
    public function __construct(private Journal $journal)
    {
    }

    public function __invoke(TaskCompleted $event): void
    {
        $this->journal->lines[] = "completed #{$event->id}";
    }
}
