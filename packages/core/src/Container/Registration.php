<?php

declare(strict_types=1);

namespace Lava\Core\Container;

use Lava\Core\Problem\SourceLocation;

/** The internal record of one container registration. Introspection reads it via {@see ServiceRecord}. */
/**
 * One entry in the container: how an id is built, and where it was registered.
 *
 * @internal built by singleton()/factory()/value()/alias(); read through Container::describe()
 */
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