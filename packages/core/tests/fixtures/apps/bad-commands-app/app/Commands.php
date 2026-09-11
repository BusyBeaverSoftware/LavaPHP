<?php

declare(strict_types=1);

// The wrong shape on purpose: app/Commands.php must RETURN a callable that
// registers commands. Returning a value instead is an invalid_config problem
// naming the file, not a crash — the same contract every other user artifact
// keeps (missing means "none of that", wrong shape is a diagnosis).

return 'not a callable';
