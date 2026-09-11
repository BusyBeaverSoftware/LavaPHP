<?php

declare(strict_types=1);

use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;

// 'redefined_pack' is the pack's own gate flag — declaring it here is exactly
// the mistake CollectFlagDefinitions reports.
return [
    'define' => [
        Feature::define('redefined_pack', Flag::on()),
    ],
];
