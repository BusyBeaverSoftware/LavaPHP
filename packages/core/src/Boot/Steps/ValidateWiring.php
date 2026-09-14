<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Features\FlagSubjectResolver;
use Lava\Core\Problem\CircularService;
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

        // A test's replacements are checked first, and only here: every
        // registration exists now, so "nothing registers this id" is true
        // rather than "not yet".
        foreach ($container->replacementProblems() as $problem) {
            $ctx->problems->add($problem);
        }

        foreach ($container->ids() as $id) {
            // Never fail-fast: the next registration still gets its turn. But a
            // failing singleton is not cached, so every service that depends on
            // it re-runs its factory and re-throws the same problem — once per
            // dependent, and once per service on a cycle. The sweep reports the
            // diagnosis once; the second resolution adds nothing a reader can
            // act on.
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
        if ($ctx->problems->includes($problem)) {
            return;
        }

        // A cycle's chain starts wherever the sweep came in: at A for
        // A -> B -> A, at B for B -> A -> B, and at P for a P that only depends
        // on it. Each is a different context, so the report's own identity
        // would keep one copy per service; the cycle is one mistake with one
        // fix (Lava Notes, R3-B2).
        if ($problem instanceof CircularService) {
            foreach ($ctx->problems->problems() as $reported) {
                if ($reported instanceof CircularService && self::cycle($reported) === self::cycle($problem)) {
                    return;
                }
            }
        }

        $ctx->problems->add($problem);
    }

    /** @return list<string> the ids on the cycle itself, sorted, without the path that led into it */
    private static function cycle(CircularService $problem): array
    {
        $chain = $problem->context['chain'] ?? null;
        $ids = is_array($chain) ? array_values(array_filter($chain, is_string(...))) : [];
        if ($ids === []) {
            return [];
        }

        $start = array_search($ids[count($ids) - 1], $ids, true);
        $members = array_slice($ids, is_int($start) ? $start : 0, -1);
        sort($members);

        return $members;
    }
}
