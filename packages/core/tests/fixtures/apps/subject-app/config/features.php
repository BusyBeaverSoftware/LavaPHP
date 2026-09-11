<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

return [
    'define' => [
        // users targeting: decided per request, from the subject the resolver derives.
        Feature::define('team_preview', Flag::users('u1'), description: 'The team board, for the seeded pilot user'),
        // rollout:100 — every identified subject is in the bucket, anonymous is not.
        Feature::define('slow_rollout', Flag::rollout(100), description: 'Early features, for identified subjects'),
    ],
    'set' => [],
];