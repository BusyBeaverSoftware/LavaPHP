<?php

declare(strict_types=1);

namespace Lava\Core\Features;

use Lava\Core\Problem\InvalidFlagValue;

/**
 * A flag SETTING (not yet resolved for any environment or subject).
 * Immutable; constructed only through the named constructors so every flag
 * in every file reads the same way.
 */
final readonly class Flag
{
    /**
     * @param list<string> $userIds
     * @param array<string, Flag> $perEnv env name => flag
     */
    private function __construct(
        public FlagKind $kind,
        public int $percentage,
        public array $userIds,
        public array $perEnv,
    ) {
    }

    public static function on(): self
    {
        return new self(FlagKind::On, 0, [], []);
    }

    public static function off(): self
    {
        return new self(FlagKind::Off, 0, [], []);
    }

    /** Deterministic sticky bucketing: a subject stays on the same side across requests and machines. */
    public static function rollout(int $percentage): self
    {
        if ($percentage < 0 || $percentage > 100) {
            throw InvalidFlagValue::of((string) $percentage, 'rollout percentage must be between 0 and 100');
        }
        return new self(FlagKind::Rollout, $percentage, [], []);
    }

    public static function users(string ...$ids): self
    {
        foreach ($ids as $id) {
            if ($id === '' || str_contains($id, ',')) {
                throw InvalidFlagValue::of($id, 'user ids must be non-empty and must not contain commas');
            }
        }
        return new self(FlagKind::Users, 0, array_values($ids), []);
    }

    /** @param array<string, Flag> $perEnv e.g. ['dev' => Flag::on(), 'prod' => Flag::off()] */
    public static function env(array $perEnv): self
    {
        if ($perEnv === []) {
            throw InvalidFlagValue::of('env:{}', 'a per-env flag needs at least one branch');
        }
        foreach ($perEnv as $envName => $branch) {
            if ($envName === '') {
                throw InvalidFlagValue::of('env:', 'branch names must be non-empty');
            }
            if ($branch->kind === FlagKind::PerEnv) {
                throw InvalidFlagValue::of('env:' . $envName . '=env:…', 'per-env flags cannot nest');
            }
        }
        return new self(FlagKind::PerEnv, 0, [], $perEnv);
    }

    /**
     * Parses the one-line syntax used by env overrides (LAVA_FEATURE_*) and the CLI:
     * on | off | rollout:<0-100> | users:<id,id,…> | env:<name=on|off|…>
     */
    public static function parse(string $syntax): self
    {
        if ($syntax === 'on') {
            return self::on();
        }
        if ($syntax === 'off') {
            return self::off();
        }
        if (preg_match('/^rollout:(\d+)$/', $syntax, $m) === 1) {
            return self::rollout((int) $m[1]);
        }
        if (preg_match('/^users:(.+)$/', $syntax, $m) === 1) {
            $ids = array_map(trim(...), explode(',', $m[1]));
            return self::users(...$ids);
        }
        if (preg_match('/^env:(.+)$/', $syntax, $m) === 1) {
            $branches = [];
            foreach (explode(',', $m[1]) as $pair) {
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    throw InvalidFlagValue::of($syntax, "branch '{$pair}' must be name=flag");
                }
                $branches[trim(substr($pair, 0, $eq))] = self::parse(trim(substr($pair, $eq + 1)));
            }
            return self::env($branches);
        }
        throw InvalidFlagValue::of($syntax, 'unrecognized syntax');
    }

    /** Canonical one-line form — the inverse of parse(). */
    public function setting(): string
    {
        return match ($this->kind) {
            FlagKind::On => 'on',
            FlagKind::Off => 'off',
            FlagKind::Rollout => "rollout:{$this->percentage}",
            FlagKind::Users => 'users:' . implode(',', $this->userIds),
            FlagKind::PerEnv => 'env:' . implode(',', array_map(
                static fn (string $env, Flag $flag): string => "{$env}={$flag->setting()}",
                array_keys($this->perEnv),
                $this->perEnv,
            )),
        };
    }
}