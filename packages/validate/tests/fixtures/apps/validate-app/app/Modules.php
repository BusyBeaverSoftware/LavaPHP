<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

return [
    // Enabled unconditionally: lava/validate has no config and no env vars, so
    // there is nothing for a gate to decide, and the interesting failures are
    // the ones the rules report rather than the ones a flag reports.
    ModuleRef::of(\Lava\Validate\ValidateModule::class, package: 'lava/validate', feature: 'validate'),
];
