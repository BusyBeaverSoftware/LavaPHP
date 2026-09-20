<?php

declare(strict_types=1);

use App\EventsFixture\Journal;
use App\EventsFixture\JournalAudit;
use App\EventsFixture\JournalCompletion;
use App\EventsFixture\JournalLabel;
use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;

return function (Container $c, AppContext $ctx): void {
    $c->singleton(Journal::class, static fn (): Journal => new Journal());
    $c->singleton(JournalCompletion::class, static fn (Container $c): JournalCompletion => new JournalCompletion($c->get(Journal::class)));
    $c->singleton(JournalLabel::class, static fn (Container $c): JournalLabel => new JournalLabel($c->get(Journal::class)));
    $c->singleton(JournalAudit::class, static fn (Container $c): JournalAudit => new JournalAudit($c->get(Journal::class)));
};
