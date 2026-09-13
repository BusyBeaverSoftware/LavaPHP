<?php

declare(strict_types=1);

namespace App;

use Psr\Log\AbstractLogger;

/** Keeps every entry, so a test can read what the app logged. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $entries = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
