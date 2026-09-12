<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\Console;
use Lava\Core\Console\IO;

/**
 * Runs `lava` commands in-process against an app — the app's own commands
 * included — and hands back the exit code and everything the command wrote.
 *
 * The same path as the binary: {@see Console} dispatches the command, an app
 * command boots the app exactly as `vendor/bin/lava <command>` would, and
 * {@see json()} returns the envelope an agent reads. In-process, so a command
 * test runs as fast as an HTTP test through {@see TestClient}; isolated, so the
 * `config/.env` values a boot promotes do not leak into the next test.
 *
 * ```php
 * $result = (new TestConsole(dirname(__DIR__)))->json('app:report');
 *
 * self::assertSame(0, $result->exitCode());
 * self::assertSame(3, $result->data()['rows']);
 * ```
 *
 * What a command does to the outside world is still the command's: one that
 * fetches a URL fetches it. Test that logic against an injected fake — a PSR-18
 * client, a repository — and use this for the command's contract: its flags,
 * its exit code and its envelope.
 */
final class TestConsole
{
    /**
     * @param string $appDir the app's root — the directory holding `app/` and `config/`
     * @param array<string, string> $env variables visible to every command this console runs
     */
    public function __construct(
        private readonly string $appDir,
        private readonly array $env = [],
    ) {
    }

    /** Run a command for its human-readable output. */
    public function run(string ...$args): CommandResult
    {
        return $this->invoke(false, array_values($args));
    }

    /** Run a command with `--json`, for its envelope. */
    public function json(string ...$args): CommandResult
    {
        return $this->invoke(true, [...array_values($args), '--json']);
    }

    /** @param list<string> $args */
    private function invoke(bool $json, array $args): CommandResult
    {
        $stdout = self::stream();
        $stderr = self::stream();
        $io = new IO($json, false, $stdout, $stderr);
        $appDir = rtrim($this->appDir, '/');

        $exitCode = IsolatedEnvironment::run(
            $this->env,
            static fn (): int => (new Console(CommandRegistry::core(), $appDir))->run(['lava', ...$args], $io),
        );

        return new CommandResult($exitCode, self::drain($stdout), self::drain($stderr));
    }

    /** @return resource */
    private static function stream()
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open an in-memory stream for command output.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private static function drain($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }
}
