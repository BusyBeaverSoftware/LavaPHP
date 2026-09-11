<?php

declare(strict_types=1);

use Lava\Core\Config\EnvVar;
use Lava\Core\Container\Container;

// A fixture app whose whole purpose is the inspection commands: it declares
// env vars (required, optional, secret, and one deliberately mis-named) and a
// config key that LOOKS secret but is not. No classes, no routes — an app
// needs neither, and keeping it class-free avoids colliding with the other
// fixtures' App\ classes, which all share one PHP process under PHPUnit.
return function (Container $c): void {
    $c->value(EnvVar::CONTAINER_ID, [
        // Required and unset: `lava env` must warn, not fail.
        EnvVar::required('STRIPE_SECRET', 'Stripe API secret used for charges.', secret: true),
        // Present only in config/.env — proves the dotenv source.
        EnvVar::optional('APP_REGION', 'Deployment region.'),
        // Named like a credential, declared as not one: the declaration wins,
        // so its value must be visible even without --reveal.
        EnvVar::optional('LEGACY_TOKEN', 'Not a credential, despite the name.', secret: false),
        // Optional and unset: silent, because nothing asked for it.
        EnvVar::optional('OPTIONAL_UNSET', 'Nobody sets this.'),
    ]);
};
