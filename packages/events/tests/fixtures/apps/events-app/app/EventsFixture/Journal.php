<?php

declare(strict_types=1);

namespace App\EventsFixture;

/** What the listeners did, in order, for the response to show. */
final class Journal
{
    /** @var list<string> */
    public array $lines = [];
}
