<?php

declare(strict_types=1);

use App\EventsFixture\JournalCompletion;
use App\EventsFixture\JournalLabel;
use App\EventsFixture\Labelled;
use App\EventsFixture\TaskCompleted;

// TaskCompleted is Labelled too, so it reaches both entries, and JournalLabel,
// named under both, runs once. JournalCompletion takes only a TaskCompleted, so
// it cannot be listed under Labelled: any other Labelled event would reach it.
return [
    TaskCompleted::class => [JournalCompletion::class, JournalLabel::class],
    Labelled::class => [JournalLabel::class],
];
