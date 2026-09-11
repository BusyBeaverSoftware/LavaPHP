<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\BootFailure;
use Lava\Core\Console\AppBoot;
use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\ExitCode;
use Lava\Core\Console\IO;
use Lava\Core\Problem\BadUsage;
use Lava\Core\Problem\MissingEntryPoint;
use Lava\Core\Problem\ProblemReport;

/**
 * `lava serve` — the PHP built-in server, wired the way this app needs it.
 *
 * A plain Command, and deliberately so: an app that does NOT boot must still
 * be servable. `public/index.php` renders the diagnostics page for a broken
 * app, and "why is my app broken" is answered far better in a browser than in
 * a CLI report when you are already debugging a request. The envelope records
 * the fact (`booted: false`) instead of refusing to serve.
 *
 * The envelope is emitted BEFORE the server starts, so `--json` consumers get
 * the URL immediately and can curl it while the process stays in the
 * foreground — spawn, read one line, request. A bind failure surfaces as the
 * child's own exit code and its stderr, which is passed straight through.
 */
final class ServeCommand extends Command
{
    /** The bind address, port, and worker count a bare `lava serve` uses. */
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 8080;
    private const DEFAULT_WORKERS = 4;

    /**
     * Relative to the app root, and named once: the payload reports them and
     * the child process is pointed at them, so the two must be the same file.
     */
    private const DOC_ROOT = 'public';
    private const ENTRY_POINT = 'public/index.php';

    public function name(): string
    {
        return 'serve';
    }

    public function summary(): string
    {
        return 'Serve the app over HTTP with the PHP built-in server.';
    }

    public function flags(): array
    {
        return ['json', 'host', 'port', 'workers', 'env'];
    }

    public function usage(): string
    {
        return 'lava serve [--host=<addr>] [--port=<n>] [--workers=<n>] [--env=<name>] [--json]';
    }

    /**
     * A server that was never started: every value at its default, `booted`
     * false.
     *
     * This is the shape of an invocation the kernel rejected (an undeclared
     * flag) — `lava.serve/1` requires all seven keys, and none of them was
     * decided, so each reports what a bare `lava serve` would use. The defaults
     * are the same constants `run()` resolves through, so a payload of
     * placeholders cannot announce a port the server would not have bound.
     *
     * @return array<string, mixed>
     */
    public function emptyPayload(Args $args): array
    {
        return [
            'host' => self::DEFAULT_HOST,
            'port' => self::DEFAULT_PORT,
            'workers' => self::DEFAULT_WORKERS,
            'url' => self::url(self::DEFAULT_HOST, self::DEFAULT_PORT),
            'doc_root' => self::DOC_ROOT,
            'entry_point' => self::ENTRY_POINT,
            'booted' => false,
        ];
    }

    /** The URL to request, built in one place so no two copies can drift. */
    private static function url(string $host, int $port): string
    {
        return "http://{$host}:{$port}";
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        // One place decides what a usable port or worker count is, and it hands
        // back the value to USE — so the payload cannot disagree with the check.
        // An unusable value falls back to the default rather than reaching the
        // payload: `data.port` is a port number, 99999 is not one, and the
        // invocation's bad input is not lost — it travels in the problem's
        // context, which is where this framework puts failing inputs.
        $usage = $this->usage();
        $report = new ProblemReport();

        $host = $args->value('host') ?? self::DEFAULT_HOST;
        $port = self::number($args, 'port', self::DEFAULT_PORT, 65535, 'an integer 1-65535', $usage, $report);
        $workers = self::number($args, 'workers', self::DEFAULT_WORKERS, PHP_INT_MAX, 'a positive integer', $usage, $report);

        // Seeded BEFORE anything can fail — the same contract AppCommand keeps:
        // a `--json` consumer must not have to branch on a shape that is only
        // sometimes there. `booted: false` is accurate until the boot below
        // actually happens. The kernel seeds this same shape before dispatching,
        // for the envelopes it emits without this command running; the values
        // are identical, so which seed happens first cannot change the output.
        $io->data('host', $host);
        $io->data('port', $port);
        $io->data('workers', $workers);
        $io->data('url', self::url($host, $port));
        $io->data('doc_root', self::DOC_ROOT);
        $io->data('entry_point', self::ENTRY_POINT);
        $io->data('booted', false);

        if (!$report->isEmpty()) {
            $io->emit($this->name(), $report);
            return ExitCode::Usage;
        }

        // Checked before anything is announced: a server that starts and then
        // 404s everything looks like a working server with a broken app, which
        // is the worst possible diagnosis.
        $entryPoint = $appDir . '/' . self::ENTRY_POINT;
        if (!is_file($entryPoint)) {
            $report = new ProblemReport();
            $report->add(MissingEntryPoint::in($appDir));
            return $io->emit($this->name(), $report);
        }

        $url = self::url($host, $port);

        $boot = AppBoot::boot($appDir, $args->value('env'));

        $io->data('booted', !$boot instanceof BootFailure);

        $io->text("serving {$url} ({$workers} worker(s), doc root " . self::DOC_ROOT . "/)\n");
        $io->text('entry point: ' . self::ENTRY_POINT . "\n");
        $io->text("press Ctrl-C to stop\n");

        if ($boot instanceof BootFailure) {
            $io->error(sprintf(
                'note: this app does not boot (%d problem(s)) — every request will render the diagnostics page.',
                count($boot->problems->problems()),
            ));
        }

        // Emitted now, not after: the process is about to block for as long as
        // the server runs, and a consumer waiting for the envelope would wait
        // forever. The exit code below is the server's, not this envelope's.
        $io->emit($this->name(), $boot instanceof BootFailure ? null : $boot->problems);

        return $this->serve($host, $port, $workers, $appDir, $entryPoint);
    }

    /**
     * A numeric flag, defaulted when it is absent OR unusable.
     *
     * Absent and invalid collapse into one path deliberately: both mean "there
     * is no value to serve with", and both need a payload the schema accepts.
     * Splitting them is what let `data.port` reach the wire as 99999 — a value
     * that satisfies the shape but not the meaning, and that a `--json` consumer
     * would have had to re-validate before trusting. The problem it records
     * still carries the raw input, so nothing is hidden from the reader.
     *
     * The failure is a usage mistake (exit 2) rather than a build failure: it is
     * about how the command was typed, not about the app.
     */
    private static function number(
        Args $args,
        string $flag,
        int $default,
        int $max,
        string $expectation,
        string $usage,
        ProblemReport $report,
    ): int {
        $raw = $args->value($flag);
        if ($raw === null) {
            return $default;
        }

        if (ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= $max) {
            return (int) $raw;
        }

        $report->add(BadUsage::invalid($flag, $raw, $expectation, $usage));
        return $default;
    }

    private function serve(string $host, int $port, int $workers, string $appDir, string $entryPoint): int
    {
        // proc_open REPLACES the child's environment, so the worker count is
        // added to a copy of this process's own environment rather than to a
        // fresh array — the server still needs PATH, and everything else.
        $environment = getenv();
        $environment['PHP_CLI_SERVER_WORKERS'] = (string) $workers;

        // An ARRAY command, not a string. PHP runs an array through execve
        // directly, where a string is handed to `/bin/sh -c` — and that shell is
        // why a stopped `lava serve` used to leave the server running: a signal
        // sent to this process reached the shell, and `php -S` never heard it.
        // The array form also removes escapeshellarg, and with it every quoting
        // rule the shell would otherwise apply to `--host`, whatever a caller
        // passes.
        $command = [
            PHP_BINARY,
            ...self::prependArguments(),
            '-S',
            "{$host}:{$port}",
            '-t',
            $appDir . '/' . self::DOC_ROOT,
            $entryPoint,
        ];

        // The child inherits this process's own streams, so the server log goes
        // straight to the terminal — no buffering, no interception, and Ctrl-C
        // reaches it the way it would if the user had typed `php -S` directly.
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $appDir, $environment);
        if (!is_resource($process)) {
            return ExitCode::Failure;
        }

        self::stopServerOnSignal($process);

        return self::waitForServer($process);
    }

    /**
     * Wait for the server, dispatching signals while it runs.
     *
     * NOT `proc_close()`. `php -S` runs until something stops it, and PHP's
     * `waitpid` wrapper retries on `EINTR` — so a signal that arrives mid-wait
     * is remembered but never dispatched: dispatching needs the VM to reach a
     * safe point, and the VM is parked inside a syscall that keeps restarting.
     * The handler below would be dead code, and the server would outlive this
     * process exactly as it did before there was a handler at all. Polling puts
     * opcodes back between waits, which is what gives the signal somewhere to
     * run — the cost is one 20ms nap per interval, on a process whose job is to
     * sit there for hours.
     *
     * The exit code comes from `proc_get_status` for the same reason: once a
     * status poll has reaped the child, `proc_close` has nothing left to report
     * and returns -1 whatever happened.
     *
     * @param resource $process
     */
    private static function waitForServer($process): int
    {
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);

                // A child killed by a signal reports -1 here, which is a
                // non-zero exit and so a failure — correct for a server that
                // stopped without being asked, and unreachable for Ctrl-C
                // because the handler exits first.
                return $status['exitcode'] === 0 ? ExitCode::Ok : ExitCode::Failure;
            }

            usleep(20_000);
        }
    }

    /**
     * Stop the server when this process is asked to stop.
     *
     * A `php -S` child is a separate process: it does NOT die with its parent.
     * In a terminal that is invisible, because Ctrl-C signals the whole
     * foreground process group and both processes receive it. Every OTHER way of
     * stopping `lava serve` — `kill <pid>` from a script, an agent stopping a
     * server it started in the background, a CI cleanup trap — reaches only this
     * process, and the server keeps the port, serving stale code to whatever
     * runs next. That is not hypothetical: this framework's own HTTP test
     * harness carries a `pkill` at its call site to clean up afterwards, which
     * is the workaround this replaces.
     *
     * pcntl is what makes a handler run at all — without it, SIGTERM's default
     * disposition ends this process with no chance to act, and the child is
     * orphaned exactly as before. So it is guarded rather than required:
     * `lava serve` has to work on a PHP built without process control.
     *
     * The exit code is 128 + the signal, which is what a shell reports for a
     * process killed by that signal — so Ctrl-C still looks like Ctrl-C to
     * whatever is waiting on this one.
     *
     * @param resource $process
     */
    private static function stopServerOnSignal($process): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = static function (int $signal) use ($process): void {
            proc_terminate($process);
            exit(128 + $signal);
        };

        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    /**
     * The parent's own `auto_prepend_file`, handed to the server child as `-d`.
     *
     * Both processes are the same CLI SAPI reading the same php.ini, so every
     * file-based setting already agrees; a `-d` override is the one thing that
     * does not survive the fork, and auto_prepend_file is the only override
     * that changes WHICH CODE RUNS. Dropping it would make `lava serve` serve a
     * different app than `lava check` just validated — the boot would be
     * missing whatever the prepend provides (a class loader, most often) and
     * every request would render a diagnostics page for a problem the CLI
     * cannot see.
     *
     * @return list<string>
     */
    private static function prependArguments(): array
    {
        $prepend = ini_get('auto_prepend_file');
        if (!is_string($prepend) || $prepend === '' || strtolower($prepend) === 'none') {
            return [];
        }

        return ['-d', 'auto_prepend_file=' . $prepend];
    }
}
