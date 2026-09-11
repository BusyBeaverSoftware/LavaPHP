<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A service factory (transitively) resolves itself. The full chain is in the context. */
final class CircularService extends LavaProblem
{
    public function code(): string
    {
        return 'circular_service';
    }

    /** @param list<string> $chain the resolution chain, ending with the repeated id */
    public static function of(array $chain): self
    {
        return new self(
            'Circular service dependency: ' . implode(' -> ', $chain) . '.',
            'Break the cycle: move the shared dependency out of one constructor,'
            . ' or have one factory fetch it lazily (on first use) instead of at construction.',
            ['chain' => $chain],
        );
    }
}