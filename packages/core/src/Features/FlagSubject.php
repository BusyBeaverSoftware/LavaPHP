<?php

declare(strict_types=1);

namespace Lava\Core\Features;

/**
 * Who is asking: the subject a flag is evaluated for (a user id, a visitor
 * id). Null means anonymous — rollout and user-targeted flags resolve OFF
 * for anonymous subjects, by documented policy: anonymous bucketing gives
 * flaky UX and flaky tests.
 */
final readonly class FlagSubject
{
    public function __construct(public string $id)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('FlagSubject id must be non-empty');
        }
    }
}