<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Code asked the container for an id that was never registered.
 * Implements PSR-11's NotFoundExceptionInterface so the container stays
 * spec-compliant while still throwing a self-correcting LavaProblem.
 */
final class ServiceNotRegistered extends LavaProblem implements NotFoundExceptionInterface
{
    public function code(): string
    {
        return 'service_not_registered';
    }

    public static function of(string $id, ?string $referencedFrom = null, ?string $note = null): self
    {
        $message = "Service '{$id}' is not registered in the container.";
        if ($note !== null) {
            $message .= " ({$note})";
        }
        return new self(
            $message,
            "Register it in app/Services.php: \$c->singleton({$id}::class, fn (Container \$c) => new {$id}(…)),"
            . " or remove the reference to it.",
            array_filter(['id' => $id, 'referenced_from' => $referencedFrom], static fn ($v) => $v !== null),
        );
    }
}