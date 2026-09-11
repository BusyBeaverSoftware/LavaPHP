<?php

declare(strict_types=1);

// Global middleware — wraps every route, outermost first. Class-strings,
// resolved from the container at request time, so each one is registered in
// app/Services.php like any other service.
return [
    \App\Http\RequestIdMiddleware::class,
];
