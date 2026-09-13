<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Clock\SystemClock;
use Lava\Core\Log\LineLogger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers the services core supplies only when nothing else did — exactly
 * {@see \Lava\Core\Boot\Kernel::DEFAULT_SERVICES}, in order.
 *
 * Every other id is registered once, and a second registration is fatal. These
 * two are the exception, and it is narrow on purpose: `LoggerInterface` and
 * `ClockInterface` are PSR standard ids, the ones an app most often wants to fill
 * with a library of its own — Monolog, a clock with a fixed zone. Registered up
 * front with the core services, core would OCCUPY them, and an app registering
 * its own would get a `duplicate_service` with no way around it — the reason
 * `lavaphp/http-client` never claims `ClientInterface` (decision 94). Registered
 * here, after every pack and app/Services.php, core fills only what is still
 * empty. Nothing is overridden or replaced: the id is registered once, by
 * whoever registered it first, and `lava services` names that file.
 *
 * Before BuildRouter, because a handler may type-hint either id and its
 * injection plan is built there.
 */
final class RegisterDefaultServices implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        $container = $ctx->container;
        if ($container === null) {
            return; // a fatal upstream already stopped the chain
        }

        if (!$container->has(LoggerInterface::class)) {
            $container->alias(LoggerInterface::class, LineLogger::class);
        }
        if (!$container->has(ClockInterface::class)) {
            $container->singleton(ClockInterface::class, static fn (): ClockInterface => new SystemClock());
        }
    }
}
