<?php

declare(strict_types=1);

namespace Lava\Core\Console;

use Lava\Core\Config\ProcessEnv;
use Lava\Core\Problem\BadTestReport;
use Lava\Core\Problem\MissingTestRunner;

/**
 * Runs an app's own PHPUnit and returns the parsed result.
 *
 * The app's runner, not the framework's: PHPUnit is a dev dependency of the
 * app being verified, and using the app's own binary is the only way `lava
 * test` can agree with what `composer test` would do. The run happens with the
 * app directory as the working directory, so PHPUnit picks up the app's own
 * phpunit.xml.dist exactly as a developer's shell would.
 *
 * Results come back through `--log-junit`, never by scraping PHPUnit's summary
 * line: that line is a human convenience PHPUnit may reword, and a framework
 * that parses it would break on an upgrade with no code change of its own.
 */
final class PhpUnitRunner
{
    public function __construct(private readonly string $appDir)
    {
    }

    /**
     * @param string|null $filter PHPUnit `--filter` pattern
     * @param string|null $env    LAVA_ENV to export for the run, so a suite can
     *                            be exercised against a specific environment
     * @throws MissingTestRunner when the app has no runner to run
     * @throws BadTestReport     when PHPUnit produced no usable report
     */
    public function run(?string $filter = null, ?string $env = null): TestRun
    {
        $junit = tempnam(sys_get_temp_dir(), 'lava-junit-');
        if ($junit === false) {
            throw BadTestReport::empty(0, 'Could not create a temporary file for the JUnit report.');
        }

        $exitCode = 0;
        $output = '';
        try {
            [$exitCode, $output] = $this->execute($this->binary(), $junit, $filter, $env);
            $xml = is_file($junit) ? (string) file_get_contents($junit) : '';
        } finally {
            if (is_file($junit)) {
                unlink($junit);
            }
        }

        // No report at all means PHPUnit never reached the testing phase — the
        // exit code and PHPUnit's own output are the whole diagnosis.
        if ($xml === '') {
            throw BadTestReport::empty($exitCode, $output);
        }

        return Junit::parse($xml);
    }

    /** @throws MissingTestRunner */
    private function binary(): string
    {
        $override = ProcessEnv::real('LAVA_PHPUNIT');
        if ($override !== null && is_file($override)) {
            return $override;
        }
        $local = $this->appDir . '/vendor/bin/phpunit';
        if (is_file($local)) {
            return $local;
        }
        throw MissingTestRunner::in($this->appDir);
    }

    /** @return array{0: int, 1: string} exit code and combined output */
    private function execute(string $binary, string $junit, ?string $filter, ?string $env): array
    {
        $parts = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($binary),
            '--log-junit',
            escapeshellarg($junit),
        ];
        if ($filter !== null) {
            $parts[] = '--filter';
            $parts[] = escapeshellarg($filter);
        }

        // proc_open REPLACES the child's environment when given one, so the
        // override is applied to a copy of this process's real environment
        // rather than to a fresh array — the runner must still see PATH,
        // COMPOSER_*, and everything else the shell would have given it.
        $environment = null;
        if ($env !== null) {
            $environment = getenv();
            $environment['LAVA_ENV'] = $env;
        }

        $process = proc_open(
            implode(' ', $parts),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->appDir,
            $environment,
        );
        if (!is_resource($process)) {
            throw BadTestReport::empty(0, 'Could not start the test runner process.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout . $stderr];
    }
}
