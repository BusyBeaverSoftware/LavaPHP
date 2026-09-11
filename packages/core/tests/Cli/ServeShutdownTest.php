<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Cli;

use Lava\Core\Tests\Support\ServedApp;
use PHPUnit\Framework\TestCase;

/**
 * Stopping `lava serve` stops the server it started.
 *
 * A `php -S` child is a separate process, so it does not die with its parent. In
 * a terminal that is invisible — Ctrl-C signals the whole foreground process
 * group and both processes get it — but every programmatic stop reaches only
 * `lava serve`: a script's `kill`, an agent stopping a server it started in the
 * background, a CI cleanup trap. The server then keeps the port and serves stale
 * code to whatever runs next.
 *
 * That makes this the one test in the suite that asserts the ABSENCE of a
 * process, and so the one the framework's own harness could not paper over. It
 * used to be papered over: `ServedApp::stop()` followed its `proc_terminate`
 * with a `pkill` for the port. The sweep is gone now that `ServeCommand`
 * terminates its server itself, which means this test fails if that regresses.
 *
 * It gets a server of its own rather than sharing `ServeTest`'s, because a class
 * that shuts its servers down in `tearDownAfterClass` cannot also assert that
 * shutting one down worked.
 */
final class ServeShutdownTest extends TestCase
{
    public function testStoppingTheCommandStopsTheServerItStarted(): void
    {
        // `ServeCommand` guards its signal handlers behind `function_exists`,
        // because `lava serve` has to work on a PHP built without process
        // control. Where pcntl is absent the handlers are absent too, so there
        // is no behaviour here to assert — and a skip says that out loud rather
        // than passing for a reason the reader would have to guess.
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is not loaded, so `lava serve` installs no signal handlers');
        }

        $server = ServedApp::start('ok-app');
        self::assertSame(200, $server->get('/health')->status, 'the server never came up');

        $server->stop();

        // The server takes a moment to notice SIGTERM, and its workers are
        // separate processes again — so this polls rather than sleeping once and
        // hoping. Five seconds is far longer than the 20ms the command's own
        // wait loop needs.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            if (!self::isListening($server->port)) {
                self::assertTrue(true, 'the port was released');
                return;
            }
            usleep(50_000);
        }

        self::fail(sprintf(
            'port %d was still answering after `lava serve` was stopped — the server outlived the command that started it',
            $server->port,
        ));
    }

    /**
     * Whether anything accepts a connection on the port.
     *
     * A surviving `php -S` worker answers, so this is the difference between
     * "the command stopped" and "the command exited while its server kept
     * serving". Nothing is sent: connecting is the whole question.
     */
    private static function isListening(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
