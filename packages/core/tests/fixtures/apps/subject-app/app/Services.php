<?php

declare(strict_types=1);

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Lava\Core\Features\FlagSubjectResolver;

return function (Container $c, AppContext $ctx): void {
    // The container id is the interface itself — the same name the core
    // looks the resolver up by, per request.
    $c->singleton(FlagSubjectResolver::class, static fn (): FlagSubjectResolver => new \App\Http\HeaderSubjectResolver());
};