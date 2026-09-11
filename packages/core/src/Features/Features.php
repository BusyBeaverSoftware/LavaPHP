<?php

declare(strict_types=1);

namespace Lava\Core\Features;

use Lava\Core\Problem\UnknownEnvBranch;
use Lava\Core\Problem\UnknownFeature;

/**
 * The resolver. A container-registered service handlers can type-hint.
 *
 * Resolution order (documented, deterministic, no fallbacks):
 *   1. definition lookup — undefined name is ALWAYS UnknownFeature, never silent false
 *   2. start from the code default
 *   3. apply config/features.php 'set' override if present
 *   4. apply LAVA_FEATURE_<UPPER_SNAKE> env override if set
 *   5. evaluate the resulting flag for (env, subject)
 * Every layer consulted is recorded in the trace.
 */
final class Features
{
    public function __construct(
        public readonly FeatureSet $definitions,
        public readonly FeatureSettings $overrides,
        public readonly ?FlagSubject $subject,
        public readonly string $env,
    ) {
    }

    public function on(string $name): bool
    {
        return $this->resolve($name)->enabled;
    }

    public function resolve(string $name): Resolution
    {
        $feature = $this->definitions->get($name)
            ?? throw UnknownFeature::of($name, $this->definitions->nearest($name));

        $flag = $feature->default;
        $source = FlagSource::Code;
        $trace = [['layer' => 'code', 'setting' => $flag->setting()]];
        foreach ($this->overrides->layers($name) as $layer) {
            $flag = $layer['flag'];
            $source = $layer['source'];
            $trace[] = ['layer' => $layer['source']->value, 'setting' => $flag->setting()];
        }

        return $this->evaluate($name, $flag, $source, $trace);
    }

    /** The same resolver bound to a different subject — used per request for gated routes. */
    public function forSubject(?FlagSubject $subject): self
    {
        return new self($this->definitions, $this->overrides, $subject, $this->env);
    }

    /**
     * @param list<array{layer: string, setting: string}> $trace
     */
    private function evaluate(string $name, Flag $flag, FlagSource $source, array $trace): Resolution
    {
        switch ($flag->kind) {
            case FlagKind::On:
                return new Resolution($name, true, $source, $flag->setting(), $trace);
            case FlagKind::Off:
                return new Resolution($name, false, $source, $flag->setting(), $trace);

            case FlagKind::Users:
                // The subject layer decides audience flags — never an earlier layer.
                $enabled = $this->subject !== null && in_array($this->subject->id, $flag->userIds, true);
                return new Resolution($name, $enabled, FlagSource::Subject, $flag->setting(), $trace);

            case FlagKind::Rollout:
                // Anonymous subjects resolve OFF (documented policy — see FlagSubject).
                $enabled = $this->subject !== null
                    && Bucketing::of($name, $this->subject->id) < $flag->percentage;
                return new Resolution($name, $enabled, FlagSource::Subject, $flag->setting(), $trace);

            case FlagKind::PerEnv:
                $branch = $flag->perEnv[$this->env]
                    ?? throw UnknownEnvBranch::of($name, $this->env, array_keys($flag->perEnv));
                $trace[] = ['layer' => 'env:' . $this->env, 'setting' => $branch->setting()];
                return $this->evaluate($name, $branch, $source, $trace);
        }
    }
}