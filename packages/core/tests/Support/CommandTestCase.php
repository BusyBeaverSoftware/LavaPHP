<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Support;

use Lava\Core\Config\ProcessEnv;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\Console;
use Lava\Core\Console\IO;
use Lava\Core\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Runs commands in-process against a fixture app, the way an agent runs them
 * through the binary — both views, real exit codes, real envelopes.
 *
 * In-process rather than subprocess because these tests are about the commands'
 * behaviour (sections, ordering, payload shapes) and want to be instant and
 * readable; the binary itself, the SAPI, and the schemas have their own tests
 * in tests/Cli and tests/Schema. Assertions here read the ENVELOPE for machine
 * facts and the rendered text for human ones, so the two views cannot drift
 * while the suite stays green.
 *
 * Every invocation is isolated: boot PROMOTES config/.env values into the
 * process environment and never takes them back — correct for `lava`, which
 * boots once and exits, but wrong for a test process that boots twenty times.
 * Without isolation, a `.env` value a previous command promoted would look like
 * a shell export to the next one, and `lava env`'s source column would be a lie.
 */
abstract class CommandTestCase extends TestCase
{
    /**
     * @param list<string> $args
     * @return array{int, array<string, mixed>} exit code and decoded envelope
     */
    protected function json(string $fixture, array $args): array
    {
        [$io, $stdout] = $this->io(json: true);
        $code = $this->isolated(
            fn (): int => $this->console($fixture)->run(['lava', ...$args, '--json'], $io),
            $fixture,
        );

        $decoded = json_decode($this->contents($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return [$code, $decoded];
    }

    /** @param list<string> $args */
    protected function text(string $fixture, array $args): string
    {
        [$io, $stdout] = $this->io(json: false);
        $this->isolated(fn (): int => $this->console($fixture)->run(['lava', ...$args], $io), $fixture);

        return $this->contents($stdout);
    }

    /** @param list<string> $args */
    protected function stderr(string $fixture, array $args): string
    {
        [$io, , $stderr] = $this->io(json: false);
        $this->isolated(fn (): int => $this->console($fixture)->run(['lava', ...$args], $io), $fixture);

        return $this->contents($stderr);
    }

    /**
     * @param string|null $fixture the app the invocation runs against, when the
     *                             harness has something to lend it
     */
    protected function isolated(callable $body, ?string $fixture = null): int
    {
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $realBefore = getenv();

        if ($fixture !== null) {
            $this->lendRunner($fixture);
        }

        try {
            return $body();
        } finally {
            self::restoreEnv($envBefore, $serverBefore, $realBefore);
        }
    }

    /**
     * Stands in for the vendor/ a real app would have: a fixture that declares
     * a suite gets this repo's PHPUnit through LAVA_PHPUNIT, exactly as
     * {@see LavaCli} gives it to the child process. Without it, `lava test` on
     * ok-app would report `missing_test_runner` — correct for the fixture as it
     * exists on disk, and useless as a test of the run itself.
     *
     * A fixture with no phpunit config gets nothing, so the missing-runner path
     * stays reachable and honest.
     */
    private function lendRunner(string $fixture): void
    {
        $appDir = TestApp::fixturePath($fixture);
        if (!LavaCli::declaresASuite($appDir) || !is_file(LavaCli::repoPhpUnit())) {
            return;
        }
        if (ProcessEnv::real('LAVA_PHPUNIT') !== null) {
            return; // the environment already said which runner to use
        }

        $runner = LavaCli::repoPhpUnit();
        $_ENV['LAVA_PHPUNIT'] = $runner;
        $_SERVER['LAVA_PHPUNIT'] = $runner;
        putenv("LAVA_PHPUNIT={$runner}");
    }

    protected function console(string $fixture): Console
    {
        return new Console(CommandRegistry::core(), TestApp::autoloadFixture($fixture));
    }

    /**
     * Runs with the given variables in the real process environment, restored
     * afterwards. A command reads the live environment (that is the whole point
     * of `lava env`), so the test has to mutate it the way a shell would.
     *
     * @param array<string, string> $vars
     */
    protected function withEnv(array $vars, callable $body): void
    {
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $realBefore = getenv();

        foreach ($vars as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            $body();
        } finally {
            self::restoreEnv($envBefore, $serverBefore, $realBefore);
        }
    }

    /**
     * @param array<string, mixed> $envBefore
     * @param array<string, mixed> $serverBefore
     * @param array<string, string> $realBefore
     */
    protected static function restoreEnv(array $envBefore, array $serverBefore, array $realBefore): void
    {
        $_ENV = $envBefore;
        $_SERVER = $serverBefore;
        foreach (getenv() as $name => $value) {
            if (!array_key_exists($name, $realBefore) || $realBefore[$name] !== $value) {
                putenv(array_key_exists($name, $realBefore) ? "{$name}={$realBefore[$name]}" : $name);
            }
        }
    }

    /** @return array{IO, resource, resource} */
    protected function io(bool $json, bool $quiet = false): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        return [new IO($json, $quiet, $stdout, $stderr), $stdout, $stderr];
    }

    /** @param resource $stream */
    protected function contents($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }
}
