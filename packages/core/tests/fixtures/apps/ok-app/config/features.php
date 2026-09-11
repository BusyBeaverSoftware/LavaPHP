<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

return [
    'define' => [
        Feature::define('beta_greeting', Flag::rollout(50), description: 'The friendly greeting variant'),
    ],
    'set' => [
        // Code default is rollout:50; the deployment turns it fully on.
        'beta_greeting' => Flag::on(),
    ],
];