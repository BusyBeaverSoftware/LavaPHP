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

        $host = $args->value('host') ?? '127.0.0.1';
        $port = self::number($args, 'port', 8080, 65535, 'an integer 1-65535', $usage, $report);
        $workers = self::number($args, 'workers', 4, PHP_INT_MAX, 'a positive integer', $usage, $report);

        // Seeded BEFORE anything can fail — the same contract AppCommand keeps:
        // a `--json` consumer must not have to branch on a shape that is only
        // sometimes there. `booted: false` is accurate until the boot below
        // actually happens.
        $io->data('host', $host);
        $io->data('port', $port);
        $io->data('workers', $workers);
        $io->data('url', "http://{$host}:{$port}");
        $io->data('doc_root', 'public');
        $io->data('entry_point', 'public/index.php');
        $io->data('booted', false);

        if (!$report->isEmpty()) {
            $io->emit($this->name(), $report);
            return ExitCode::Usage;
        }

        // Checked before anything is announced: a server that starts and then
        // 404s everything looks like a working server with a broken app, which
        // is the worst possible diagnosis.
        $entryPoint = $appDir . '/public/index.php';
        if (!is_file($entryPoint)) {
            $report = new ProblemReport();
            $report->add(MissingEntryPoint::in($appDir));
            return $io->emit($this->name(), $report);
        }

        $url = "http://{$host}:{$port}";

        $boot = AppBoot::boot($appDir, $args->value('env'));

        $io->data('booted', !$boot instanceof BootFailure);

        $io->text("serving {$url} ({$workers} worker(s), doc root public/)\n");
        $io->text("entry point: public/index.php\n");
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

        $command = sprintf(
            '%s %s-S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            self::prependArgument(),
            escapeshellarg("{$host}:{$port}"),
            escapeshellarg($appDir . '/public'),
            escapeshellarg($entryPoint),
        );

        // The child inherits this process's own streams, so the server log goes
        // straight to the terminal — no buffering, no interception, and Ctrl-C
        // reaches it the way it would if the user had typed `php -S` directly.
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $appDir, $environment);
        if (!is_resource($process)) {
            return ExitCode::Failure;
        }

        return proc_close($process) === 0 ? ExitCode::Ok : ExitCode::Failure;
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
     */
    private static function prependArgument(): string
    {
        $prepend = ini_get('auto_prepend_file');
        if (!is_string($prepend) || $prepend === '' || strtolower($prepend) === 'none') {
            return '';
        }

        return '-d ' . escapeshellarg('auto_prepend_file=' . $prepend) . ' ';
    }
}
