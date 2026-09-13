<?php

declare(strict_types=1);

use App\RecordingLogger;
use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Psr\Log\LoggerInterface;

// The app claims the PSR-3 id itself. Core registers LineLogger as the default
// LoggerInterface only when nothing else did, so this is not a duplicate.
return function (Container $c, AppContext $ctx): void {
    $c->singleton(LoggerInterface::class, static fn (): LoggerInterface => new RecordingLogger());
};
