<?php

declare(strict_types=1);

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;

return function (Container $c, AppContext $ctx): void {
    $c->value('dup.first', 'one');
    $c->value('dup.first', 'two'); // Deliberate duplicate: duplicate_service, with both registration sites.
};