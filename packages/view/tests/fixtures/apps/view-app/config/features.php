<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

return [
    'define' => [
        // App-owned, so it is defined here rather than by a pack. `feature()`
        // in a template resolves it through the same resolver a route's
        // ->when() uses, which is what makes a gated page and a gated route
        // agree by construction.
        Feature::define('beta_banner', Flag::off(), description: 'The banner in page.twig'),
    ],
    'set' => [
        'beta_banner' => Flag::on(),
    ],
];
