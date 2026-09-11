<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Support;

use Lava\Core\Testing\TestApp;
use PHPUnit\Framework\Assert;

/**
 * A fixture app served over real HTTP by `lava serve` — the coverage nothing
 * else can give: RequestFactory::fromGlobals under a real SAPI, the Emitter
 * writing real headers, config/.env loading in a fresh process, and the
 * Accept-negotiated diagnostics page end to end.
 *
 * The envelope `lava serve` writes BEFORE it starts serving is the handshake:
 * it carries the URL, so the test never guesses a port and never sleeps hoping
 * the server came up. A unique free port per server means two test classes (or
 * a leftover worker from a killed run) cannot collide.
 */
final class ServedApp
{
    /** @param resource $process */
    private function __construct(
        private $process,
        public readonly int $port,
        public readonly string $url,
        /** @var array<string, mixed> the serve envelope */
        public readonly array $envelope,
        public readonly string $logFile,
    ) {
    }

    /**
     * Starts the server and blocks until it answers, or fails the test.
     *
     * @param array<string, string|null> $env extra variables; null removes one
     */
    public static function start(string $fixture, array $env = []): self
    {
        $appDir = TestApp::fixturePath($fixture);
        $port = self::freePort();
        $log = (string) tempnam(sys_get_temp_dir(), 'lava-serve-');

        $process = proc_open(
            LavaCli::argv(['serve', '--json', '--port=' . $port, '--workers=2']),
            // stderr to a file, not a pipe: a full pipe would block the child
            // mid-request, and the access log is only ever read on failure.
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
            $pipes,
            $appDir,
            LavaCli::environment($appDir, $env),
        );
        Assert::assertIsResource($process, 'could not start lava serve');
        fclose($pipes[0]);

        $line = fgets($pipes[1]);
        $envelope = is_string($line) ? json_decode(trim($line), true) : null;
        if (!is_array($envelope)) {
            proc_terminate($process);
            proc_close($process);
            Assert::fail('lava serve wrote no envelope; stderr: ' . (string) file_get_contents($log));
        }

        $server = new self($process, $port, "http://127.0.0.1:{$port}", $envelope, $log);
        $server->awaitPort();
        return $server;
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): HttpResponse
    {
        return HttpResponse::fetch($this->url, 'GET', $path, $headers);
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, array $headers = []): HttpResponse
    {
        return HttpResponse::fetch($this->url, $method, $path, $headers);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $data = $this->envelope['data'] ?? null;
        Assert::assertIsArray($data, 'the serve envelope carried no data object');
        return $data;
    }

    /**
     * Stops the master AND anything it forked.
     *
     * `php -S` with PHP_CLI_SERVER_WORKERS forks workers; terminating the master
     * can leave a worker holding the port and serving stale code to the next
     * run. The port is unique to this server, so the sweep cannot touch another.
     */
    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        exec('pkill -f ' . escapeshellarg('php -S 127.0.0.1:' . $this->port) . ' > /dev/null 2>&1');
        @unlink($this->logFile);
    }

    private function awaitPort(): void
    {
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(20_000);
        }
        Assert::fail("lava serve never answered on port {$this->port}; stderr: " . $this->log());
    }

    public function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /**
     * A port nothing is listening on: bind :0, read back what the kernel chose,
     * release it. Racing another process for it is possible in principle; in
     * practice the window is microseconds and a lost race fails loudly on the
     * first request rather than silently.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        Assert::assertIsResource($socket, "could not find a free port: {$error}");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $colon = strrpos($name, ':');
        Assert::assertNotFalse($colon, "unexpected socket name: {$name}");
        return (int) substr($name, $colon + 1);
    }
}
