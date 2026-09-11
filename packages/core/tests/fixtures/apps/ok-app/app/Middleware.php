<?php

declare(strict_types=1);

// Global middleware — wraps every route, outermost layer. Class-strings,
// resolved from the container at request time; register them in app/Services.php.
return [
    \App\Http\TimingMiddleware::class,
];