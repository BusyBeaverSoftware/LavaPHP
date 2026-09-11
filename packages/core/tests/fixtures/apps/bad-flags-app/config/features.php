<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

return [
    'define' => [
        Feature::define('beta_greeting', Flag::rollout(50), description: 'The friendly greeting variant'),
        // Deliberate duplicate: the report must carry duplicate_feature AND keep collecting the rest.
        Feature::define('beta_greeting', Flag::on(), description: 'Duplicate on purpose'),
    ],
    'set' => [
        // Deliberate typo: unknown_feature, with beta_greeting as the nearest-name fix hint.
        'beta_gretting' => Flag::on(),
    ],
];