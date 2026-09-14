<?php

declare(strict_types=1);

namespace App\EventsFixture;

final readonly class JournalLabel
{
    public function __construct(private Journal $journal)
    {
    }

    public function __invoke(Labelled $event): void
    {
        $this->journal->lines[] = "labelled {$event->label()}";
    }
}
