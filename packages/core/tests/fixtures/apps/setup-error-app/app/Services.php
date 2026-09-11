<?php

declare(strict_types=1);

use Lava\Core\Container\Container;

// setup-error-app's only app artifact, and it wires nothing: this fixture exists
// for its test suite, and an app with an `app/` directory boots green so that
// `lava check` files the finding under 'tests' rather than under 'boot'.
return function (Container $c): void {
};
