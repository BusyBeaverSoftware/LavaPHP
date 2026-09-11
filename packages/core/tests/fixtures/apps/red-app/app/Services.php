<?php

declare(strict_types=1);

use Lava\Core\Container\Container;

// red-app's only app artifact, and it wires nothing: this fixture exists for its
// test suite, and an app with an `app/` directory boots green so that `lava
// check` reports the red suite as a test finding rather than as a boot failure.
return function (Container $c): void {
};
