<?php

declare(strict_types=1);

namespace Lava\Core\Features;

/**
 * Which flag resolver answers right now: the one bound to the current request's
 * subject while a request is being handled, boot's otherwise.
 *
 * Flags are read in three places — the router's `->when()`, a handler that takes
 * `Features`, and a template's `feature()` — and an audience flag
 * (`Flag::users`, `Flag::rollout`) can only answer for a subject. `App::handle()`
 * binds the resolver to the request's subject and makes it current here, so all
 * three read one answer: a gated button and a gated route cannot disagree.
 *
 * It is the one piece of request state the container holds, and it is set and
 * restored around exactly one dispatch ({@see during()}), so a long-running
 * worker or a test process that serves many requests never carries one subject
 * into the next request. Code built once — a singleton service, middleware —
 * that needs per-request flags takes this scope and asks {@see current()} when it
 * runs. A `Features` taken in a constructor is whatever was current when that
 * singleton was built, which at boot is the anonymous resolver.
 */
final class FeatureScope
{
    private ?Features $bound = null;

    public function __construct(private readonly Features $boot)
    {
    }

    /** The resolver for whoever is being answered now. */
    public function current(): Features
    {
        return $this->bound ?? $this->boot;
    }

    /**
     * Run `$work` with `$features` current, and restore what was current before —
     * on return and on a throw alike, so a failed request cannot leak its subject
     * into the next one.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function during(Features $features, \Closure $work): mixed
    {
        $previous = $this->bound;
        $this->bound = $features;

        try {
            return $work();
        } finally {
            $this->bound = $previous;
        }
    }
}
