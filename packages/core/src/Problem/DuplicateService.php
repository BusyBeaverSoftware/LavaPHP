<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A container id was registered twice. Overriding is banned by design — overriding is magic. */
final class DuplicateService extends LavaProblem
{
    public function code(): string
    {
        return 'duplicate_service';
    }

    public static function of(string $id, SourceLocation $first, SourceLocation $second): self
    {
        return new self(
            "Service '{$id}' is registered twice.",
            "Remove the registration at {$second} (the one at {$first} was first), or use a distinct id.",
            ['id' => $id, 'first_registered_at' => (string) $first, 'second_registered_at' => (string) $second],
            $second,
        );
    }
}