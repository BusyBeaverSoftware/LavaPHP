<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Golden tests that run the REAL `bin/lava` as a subprocess. Unit tests prove
 * the console kernel's pieces; only this proves the binary is executable, finds
 * its autoloader, and puts the envelope on real stdout with the real exit code
 * — the exact thing an agent shells out to.
 */
final class LavaBinaryTest extends TestCase
{
    public function testBinaryEmitsTheListEnvelopeOnStdout(): void
    {
        [$code, $stdout, $stderr] = $this->lava(['list', '--json']);

        self::assertSame(0, $code, $stderr);
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('lava.list/1', $decoded['schema']);
        self::assertSame('ok', $decoded['status']);
        self::assertContains('list', array_column($decoded['data']['commands'], 'name'));
    }

    public function testBinaryExitsTwoOnUnknownCommand(): void
    {
        [$code, $stdout] = $this->lava(['nope', '--json']);

        self::assertSame(2, $code);
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('failed', $decoded['status']);
        self::assertSame('unknown_command', $decoded['problems'][0]['code']);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function lava(array $args): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/lava';
        self::assertFileExists($bin);

        $command = implode(' ', array_merge(
            [escapeshellarg(PHP_BINARY), escapeshellarg($bin)],
            array_map(escapeshellarg(...), $args),
        ));

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
