<?php

declare(strict_types=1);

namespace App\EventsFixture;

final readonly class TaskCompleted implements Labelled
{
    public function __construct(public int $id, public string $title)
    {
    }

    public function label(): string
    {
        return $this->title;
    }
}
