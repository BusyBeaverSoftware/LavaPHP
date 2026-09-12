<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

return [
    // The pack named here is deliberately fictional, and must stay that way.
    //
    // This fixture exists to produce missing_pack, which fires only when the
    // module class does not exist. Naming a real pack — lavaphp/db was the
    // original choice — makes the fixture silently stop testing anything the
    // moment that pack lands in the monorepo: the class starts resolving, the
    // problem disappears, and the failure surfaces in unrelated assertions.
    //
    // lavaphp/search is not one of the five packs this monorepo builds, so it
    // stays missing. KernelBootTest asserts as much, so that if it is ever
    // built the fixture fails loudly here rather than mysteriously elsewhere.
    ModuleRef::of(\Lava\Search\SearchModule::class, package: 'lavaphp/search', feature: 'search'),
    // An audience flag gating a boot-lifetime resource: invalid_gating.
    ModuleRef::of(\Lava\View\ViewModule::class, package: 'lavaphp/view', feature: 'views'),
];
