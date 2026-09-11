<?php

declare(strict_types=1);

namespace Lava\Core\Container;

use Lava\Core\Problem\SourceLocation;

/** The internal record of one container registration. Introspection reads it via {@see ServiceRecord}. */
final readonly class Registration
{
    public function __construct(
        public string $id,
        public ServiceKind $kind,
        public ?\Closure $factory,
        public mixed $value,
        public SourceLocation $declaredAt,
    ) {
    }
}