<?php

declare(strict_types=1);

namespace Lava\Validate\Tests\Support;

/**
 * A rule's refusal: the two sentences the author wrote for it.
 *
 * A test-local copy of {@see \Lava\Validate\Validation\RuleViolation} rather
 * than the class itself, so that a test asserting on a refusal cannot
 * accidentally construct one and pass. Reading is the only thing a test should
 * be able to do with a violation.
 */
final readonly class Violation
{
    public function __construct(
        public string $message,
        public string $fix,
    ) {
    }
}
