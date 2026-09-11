<?php

declare(strict_types=1);

/**
 * Global middleware — class-strings, outermost first. Each one wraps every
 * route, including the requests that end in a 404.
 *
 * They are resolved from the container at request time, so a middleware class
 * is registered in `app/Services.php` like any other service. The canonical
 * shape of one is in the framework reference at the bottom of AGENTS.md.
 */
return [];
