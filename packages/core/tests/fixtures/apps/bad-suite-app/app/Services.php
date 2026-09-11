<?php

declare(strict_types=1);

use Lava\Core\Container\Container;

// Wires nothing: this fixture exists for its broken test configuration, and an
// app whose boot is green is what makes `lava check` file the failure under
// 'tests' instead of burying it in a boot report.
return function (Container $c): void {
};
