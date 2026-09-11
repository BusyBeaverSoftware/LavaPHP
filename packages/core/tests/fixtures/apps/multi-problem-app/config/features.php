<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

return [
    'define' => [
        Feature::define('typed_flag', Flag::on(), description: 'Exists so the typo below has a nearest match'),
    ],
    'set' => [
        // Deliberate typo: unknown_feature, nearest defined name is typed_flag.
        'typo_flag' => Flag::on(),
    ],
];