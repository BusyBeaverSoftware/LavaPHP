<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A real HTTP server for the demo's outbound tests.
 *
 * The route the demo exercises leaves the process, so the test cannot fake it
 * with an in-process dispatcher: `TestClient` never touches a socket, and the
 * whole point of `lava/http-client` is what happens on one. So this is the
 * smallest thing that works — `php -S`, a router script in `tests/fixtures/`,
 * and a free port.
 *
 * **One server per test process, stopped by a shutdown function.** Starting one
 * per test would add a few hundred milliseconds to each of them for no
 * isolation the tests need: the routes are read-only apart from `/flaky-<id>`,
 * which keys its counter by the id in the path precisely so two tests cannot
 * interfere. The shutdown function is what keeps a failed run from leaving an
 * orphaned server behind.
 *
 * The same harness exists in `lava/http-client`'s own tests, and the duplication
 * is deliberate rather than overlooked. That copy exists so the pack can prove
 * its standalone claim with nothing but its own dependencies; this one exists so
 * the demo can prove the pack composes in a real app. Sharing either would make
 * one of those claims depend on the other's test namespace — which is an
 * `autoload-dev` a consumer does not get.
 */
final class UpstreamServer
{
    /**
     * The counter-file prefix, duplicated from `tests/fixtures/upstream/router.php`
     * on purpose: the server is a separate process with no autoloader, so it
     * cannot be asked. A test asserts the two copies agree.
     */
    public const COUNTER_PREFIX = 'lava-demo-upstream-flaky-';

    /** @var resource|null */
    private $process;

    private static ?self $shared = null;

    /**
     * @param resource $handle
     */
    private function __construct(
        private readonly string $base,
        private readonly string $log,
        mixed $handle,
    ) {
        /** @var resource $handle */
        $this->process = $handle;
    }

    /** Starts the server the first time it is asked for, and reuses it after. */
    public static function shared(): self
    {
        if (self::$shared !== null) {
            return self::$shared;
        }

        $router = dirname(__DIR__) . '/fixtures/upstream/router.php';
        $port = self::freePort();
        $log = (string) tempnam(sys_get_temp_dir(), 'lava-demo-upstream-');

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname($router),
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the demo upstream server');
        }

        // The write end of stdin is ours and nothing will ever use it; leaving
        // it open keeps a pipe descriptor alive for the life of the process.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $server = new self("http://127.0.0.1:{$port}", $log, $process);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            if ($server->body('/ok/health') !== null) {
                self::$shared = $server;
                register_shutdown_function(static fn (): bool => $server->stop());

                return $server;
            }
            usleep(50_000);
        }

        $server->stop();

        throw new \RuntimeException(
            "the demo upstream server did not come up on port {$port}.\n"
            . (string) file_get_contents($log),
        );
    }

    /**
     * A base URL for one upstream behaviour — `ok`, `broken`, `drop`, `echo`,
     * or `flaky-<id>`.
     *
     * The behaviour is in the PATH because the client appends `/health` to
     * whatever it is handed: a query string would land in the middle of the
     * path, and a separate port per behaviour would be five servers.
     */
    public function url(string $behaviour): string
    {
        return $this->base . '/' . $behaviour;
    }

    /** A unique id for a `/flaky-<id>` base, so tests never share a counter. */
    public static function flakyId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** How many times the client asked `/flaky-<id>` for an answer. */
    public static function attempts(string $id): int
    {
        $file = sys_get_temp_dir() . '/' . self::COUNTER_PREFIX . $id;

        return is_file($file) ? (int) file_get_contents($file) : 0;
    }

    /**
     * A plain `file_get_contents` GET, for the harness's own readiness check.
     *
     * Deliberately not the pack's client: the harness must be able to say "the
     * server is not up" without the code under test being involved in the
     * answer.
     */
    public function body(string $path): ?string
    {
        $context = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
        $body = @file_get_contents($this->base . $path, false, $context);

        return $body === false ? null : $body;
    }

    public function stop(): bool
    {
        if ($this->process === null) {
            return true;
        }

        proc_terminate($this->process);

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if ($status['running'] === false) {
                break;
            }
            usleep(20_000);
        }

        proc_close($this->process);
        $this->process = null;

        return true;
    }

    /**
     * A port nobody is listening on, found by binding one and letting it go.
     *
     * There is a race — the port could be taken between the close and the
     * server's bind — and it is the accepted one: the alternative is a
     * hardcoded port that collides with whatever else the machine is running,
     * and the failure here is a clear "did not come up" with the server's own
     * log attached.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if ($socket === false) {
            throw new \RuntimeException("could not find a free port: {$message} ({$code})");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($name)) {
            throw new \RuntimeException('could not read the bound port');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
