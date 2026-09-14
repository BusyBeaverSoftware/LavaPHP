<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

return [
    'define' => [
        Feature::define('beta_posts', Flag::off(), description: 'The beta post pages'),
    ],
];
