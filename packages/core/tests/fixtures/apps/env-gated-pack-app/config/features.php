<?php

declare(strict_types=1);

use Lava\Core\Features\Flag;

/**
 * A pack defines its own gate, so an app may only `set` it — and setting it with
 * `Flag::env` is how a deployment turns a pack off in production alone. No
 * environment variable is involved, which is what makes this the sharpest test
 * of the map's promise to write the same bytes under every `--env`.
 */
return [
    'set' => [
        'gated_pack' => Flag::env(['dev' => Flag::on(), 'test' => Flag::on(), 'prod' => Flag::off()]),
    ],
];
