<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Features\FlagSubjectResolver;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\UnexpectedFailure;

/**
 * The wiring proof: after every registration exists, resolve each one — a
 * broken factory becomes a boot problem naming its id, not a 500 on request
 * N+1. Resolution runs on the real container, so the traces it leaves behind
 * are what `lava services` reports as real deps/dependents — never static
 * analysis. Constructors do no I/O by convention; that is what keeps this
 * sweep safe enough to also power `lava check`.
 */
final class ValidateWiring implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        if ($ctx->container === null) {
            return; // a fatal upstream already stopped the chain
        }
        $container = $ctx->container;

        foreach ($container->ids() as $id) {
            // Never fail-fast: the next registration still gets its turn. But a
            // failing singleton is not cached, so every service that depends on
            // it re-runs its factory and re-throws the same problem — once per
            // dependent. The sweep reports the diagnosis once; the second
            // resolution adds nothing a reader can act on.
            try {
                $container->get($id);
            } catch (LavaProblem $problem) {
                self::report($ctx, $problem);
            } catch (\Throwable $throwable) {
                self::report($ctx, UnexpectedFailure::of(self::class, $throwable));
            }
        }

        // The subject-resolver contract fails at boot, not on request one.
        // App::handle re-checks as defense in depth, but a wrong registration
        // should surface before the app ever serves traffic.
        if ($container->has(FlagSubjectResolver::class)) {
            try {
                $resolver = $container->get(FlagSubjectResolver::class);
                if (!$resolver instanceof FlagSubjectResolver) {
                    // `get_debug_type` for the same reason as App::handle: the
                    // offending registration is by definition not a
                    // FlagSubjectResolver, and it may not be an object at all.
                    $ctx->problems->add(new InvalidConfig(
                        'The service registered under FlagSubjectResolver::class is '
                            . get_debug_type($resolver) . ', which does not implement FlagSubjectResolver.',
                        'Register a class implementing Lava\\Core\\Features\\FlagSubjectResolver in app/Services.php.',
                        ['registered' => get_debug_type($resolver)],
                    ));
                }
            } catch (\Throwable) {
                // The sweep above already reported this registration's failure.
            }
        }
    }

    private static function report(BootCtx $ctx, LavaProblem $problem): void
    {
        if (!$ctx->problems->includes($problem)) {
            $ctx->problems->add($problem);
        }
    }
}
