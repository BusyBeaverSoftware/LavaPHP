<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * One finished `lava` invocation: its exit code, its two streams, and the
 * envelope parsed off stdout. Assertions read the envelope through here rather
 * than re-decoding JSON in every test.
 */
final class LavaResult
{
    public function __construct(
        public readonly int $exit,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    /** @return array<string, mixed> */
    public function envelope(): array
    {
        $decoded = json_decode(trim($this->stdout), true);
        Assert::assertIsArray(
            $decoded,
            "stdout was not a JSON envelope (exit {$this->exit}): " . $this->stdout . ' [stderr] ' . $this->stderr,
        );
        return $decoded;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $data = $this->envelope()['data'] ?? null;
        Assert::assertIsArray($data, 'the envelope carried no data object');
        return $data;
    }

    public function schema(): string
    {
        $schema = $this->envelope()['schema'] ?? null;
        Assert::assertIsString($schema);
        return $schema;
    }

    public function status(): string
    {
        $status = $this->envelope()['status'] ?? null;
        Assert::assertIsString($status);
        return $status;
    }

    /** @return list<string> problem codes, in report order */
    public function codes(): array
    {
        $problems = $this->envelope()['problems'] ?? [];
        Assert::assertIsArray($problems);
        $codes = [];
        foreach ($problems as $problem) {
            Assert::assertIsArray($problem);
            $code = $problem['code'] ?? null;
            Assert::assertIsString($code);
            $codes[] = $code;
        }
        return $codes;
    }

    /** @return list<array<string, mixed>> */
    public function problems(): array
    {
        $problems = $this->envelope()['problems'] ?? [];
        Assert::assertIsArray($problems);
        $out = [];
        foreach ($problems as $problem) {
            Assert::assertIsArray($problem);
            $out[] = $problem;
        }
        return $out;
    }

    /** The problem with this code, or a failed assertion naming what was there. */
    public function problem(string $code): array
    {
        foreach ($this->problems() as $problem) {
            if (($problem['code'] ?? null) === $code) {
                return $problem;
            }
        }
        Assert::fail("no '{$code}' problem; got: " . implode(', ', $this->codes()));
    }

    public function context(string $code, string $key): mixed
    {
        $context = $this->problem($code)['context'] ?? [];
        Assert::assertIsArray($context);
        return $context[$key] ?? null;
    }
}
