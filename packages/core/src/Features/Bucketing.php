<?php

declare(strict_types=1);

namespace Lava\Core\Features;

/**
 * Pure, deterministic bucketing for rollout flags: crc32(feature + US + subjectId) % 100.
 * Stateless and sticky — the same subject lands in the same bucket on every
 * request, every machine, forever. Documented trade-offs: not cryptographic,
 * and mildly non-uniform (crc32 output is not perfectly even mod 100) — both
 * acceptable for rollout decisions, neither acceptable for security.
 */
final class Bucketing
{
    private const SEPARATOR = "\x1f";

    /** @return int 0-99: the subject's bucket for this feature */
    public static function of(string $feature, string $subjectId): int
    {
        return crc32($feature . self::SEPARATOR . $subjectId) % 100;
    }
}