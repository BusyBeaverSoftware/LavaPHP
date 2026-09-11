<?php

declare(strict_types=1);

use Lava\Core\Features\Flag;

return [
    'set' => [
        // Rollout is audience targeting — invalid for gating a module (boot-lifetime resource).
        'views' => Flag::rollout(50),
    ],
];