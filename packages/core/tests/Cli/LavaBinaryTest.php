<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Cli;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\LavaCli;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests that run the REAL `bin/lava` as a subprocess against real
 * fixture apps.
 *
 * Unit tests prove the console kernel's pieces; only this proves the things an
 * agent actually depends on: that the binary is executable, finds its
 * autoloader, resolves the app from the working directory rather than from its
 * own location, writes the envelope to real stdout, and exits with the real
 * code — including the cases where it has to spawn PHPUnit for the app's suite.
 */
final class LavaBinaryTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    public function testTheBinaryAnswersFromTheWorkingDirectory(): void
    {
        // The binary lives in packages/core/bin; the app is resolved from the
        // cwd, which is the whole reason `lava` can be run from an app root the
        // way composer is.
        $result = LavaCli::run(['about', '--json'], $this->fixture('ok-app'));

        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        self::assertSame('lava.about/1', $result->schema());
        self::assertStringEndsWith('ok-app', (string) $result->data()['app']['dir']);
    }

    public function testTheAppsOwnAutoloaderWinsOverTheOneBesideTheBinary(): void
    {
        // The bug this pins: the binary used to look for `autoload.php` only
        // relative to its OWN location. Installed with `vendor/lava/core` as a
        // SYMLINK — which is every path-repo install, including this monorepo's
        // own `packages/app` — `__DIR__` resolves through the link to the
        // package's real home, so the binary loaded the MONOREPO's autoloader
        // while booting the app's directory. Every class the app provided for
        // itself then read as `bad_handler: the class does not exist`, pointing
        // at a file sitting right there in the app.
        //
        // `Console::main` takes the app directory from the working directory and
        // nothing else, so the two can never legitimately disagree: `<cwd>` is
        // the app, and its autoloader is the answer. The class below is
        // reachable ONLY through the app's own vendor/ — the harness's
        // auto_prepend_file maps `App\` and nothing else — so this test fails
        // loudly if the binary ever goes back to guessing from its own path.
        $dir = $this->tempDir('lava-autoload-');
        $root = dirname(__DIR__, 4);

        self::write($dir . '/app/Routes.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Lava\Core\Routing\Router;

            return function (Router $r): void {
                $r->get('/site', 'site.show')->handler([\Site\Handler::class, 'show']);
            };
            PHP);

        // The app's own namespace, in the app's own tree.
        self::write($dir . '/site/Handler.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Site;

            use Lava\Core\Http\Responses;
            use Psr\Http\Message\ResponseInterface;

            final class Handler
            {
                public function show(): ResponseInterface
                {
                    return Responses::json(['site' => true]);
                }
            }
            PHP);

        // Stands in for the app's composer autoloader: a real install's provides
        // the framework AND the app's own namespaces, and this one does both.
        self::write($dir . '/vendor/autoload.php', sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            require %s;

            spl_autoload_register(static function (string $class): void {
                if (!str_starts_with($class, 'Site\\')) {
                    return;
                }
                $file = %s . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            });
            PHP,
            var_export($root . '/vendor/autoload.php', true),
            var_export($dir . '/site', true),
        ));

        self::assertFileExists($dir . '/site/Handler.php');

        $result = LavaCli::run(['routes', '--json'], $dir);

        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        self::assertSame([], $result->codes());
        self::assertSame(['site.show'], array_column($result->data()['routes'], 'name'));
        self::assertSame('Site\Handler', $result->data()['routes'][0]['handler']['class']);
    }

    public function testABareInvocationLists(): void
    {
        // No command name: the first token that isn't a flag wins, so `lava
        // --json` is the index rather than a usage error.
        $result = LavaCli::run(['--json'], $this->fixture('ok-app'));

        self::assertSame(ExitCode::Ok, $result->exit);
        self::assertSame('lava.list/1', $result->schema());
        self::assertContains('check', array_column($result->data()['commands'], 'name'));
    }

    public function testAnUnknownCommandExitsTwoWithTheNearestName(): void
    {
        $result = LavaCli::run(['lits', '--json'], $this->fixture('ok-app'));

        self::assertSame(ExitCode::Usage, $result->exit);
        self::assertSame(['unknown_command'], $result->codes());
        self::assertSame('list', $result->context('unknown_command', 'nearest'));
    }

    public function testADirectoryThatIsNotAnAppSaysSo(): void
    {
        // Without `not_an_app`, a random directory boots "successfully" and
        // `lava routes` there answers `ok, routes: []` — which reads as "your
        // app has no routes" rather than "there is no app here".
        $result = LavaCli::run(['about', '--json'], $this->emptyDir());

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame(['not_an_app'], $result->codes());
    }

    public function testCheckRunsTheAppsOwnSuiteAndReportsIt(): void
    {
        // The strongest golden test there is: one invocation that boots the app,
        // spawns the app's PHPUnit as a subprocess, parses its JUnit report, and
        // merges everything into one envelope with one exit code.
        $result = LavaCli::run(['check', '--json'], $this->fixture('ok-app'));

        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        self::assertSame('lava.check/2', $result->schema());
        self::assertSame('ok', $result->status());

        $tests = $result->data()['tests'];
        self::assertIsArray($tests);
        self::assertSame('ok-app', $tests['suite']);
        self::assertSame(3, $tests['tests']);
        self::assertSame(0, $tests['failures']);
    }

    public function testCheckFailsOnABrokenAppWithAProblemPerSection(): void
    {
        $result = LavaCli::run(['check', '--no-tests', '--json'], $this->fixture('broken-wiring-app'));

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame('lava.check/2', $result->schema());
        self::assertContains('service_not_registered', $result->codes());
        self::assertSame(
            'never.registered',
            $result->context('service_not_registered', 'id'),
        );
    }

    public function testCheckReportsARedSuiteWithoutAFrameworkProblem(): void
    {
        $result = LavaCli::run(['check', '--json'], $this->fixture('red-app'));

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame([], $result->codes());
        self::assertSame(1, $result->data()['tests']['failures']);
    }

    public function testCheckReportsDuplicateCommandNames(): void
    {
        $result = LavaCli::run(['check', '--no-tests', '--json'], $this->fixture('dup-command-app'));

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame(['duplicate_command', 'duplicate_command'], $result->codes());
        self::assertSame('lava/demo-pack', $result->context('duplicate_command', 'incoming_pack'));
    }

    public function testCheckReportsAWrongShapedAppArtifact(): void
    {
        $result = LavaCli::run(['check', '--no-tests', '--json'], $this->fixture('bad-commands-app'));

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame(['invalid_config'], $result->codes());
        self::assertSame('app/Commands.php', $result->context('invalid_config', 'file'));
    }

    public function testRoutesListsTheAppsRoutes(): void
    {
        $result = LavaCli::run(['routes', '--json'], $this->fixture('ok-app'));

        self::assertSame(ExitCode::Ok, $result->exit);
        self::assertSame('lava.routes/1', $result->schema());
        self::assertContains('users.show', array_column($result->data()['routes'], 'name'));
    }

    public function testDescribeResolvesARouteSelector(): void
    {
        $result = LavaCli::run(['describe', 'users.show', '--json'], $this->fixture('ok-app'));

        self::assertSame(ExitCode::Ok, $result->exit);
        self::assertSame('lava.describe/1', $result->schema());
        self::assertSame('route', $result->data()['kind']);
    }

    public function testAPackCommandIsDispatchableThroughTheBinary(): void
    {
        // The registered set and the dispatched set are two different things,
        // and only the real binary can prove they agree: a command `lava list`
        // shows but cannot run is worse than one that is absent.
        $result = LavaCli::run(['demo:ping', '--json'], $this->fixture('commands-app'));

        self::assertSame(ExitCode::Ok, $result->exit);
        self::assertSame('lava.demo.ping/1', $result->schema());
        self::assertSame(['pong' => true], $result->data());
    }

    public function testAnAppCommandIsDispatchableThroughTheBinary(): void
    {
        $result = LavaCli::run(['app:report', '--json'], $this->fixture('commands-app'));

        self::assertSame(ExitCode::Ok, $result->exit);
        self::assertStringEndsWith('commands-app', (string) $result->data()['app_dir']);
    }

    public function testTestReportsARedSuiteAsDataWithExitOne(): void
    {
        $result = LavaCli::run(['test', '--json'], $this->fixture('red-app'));

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame('lava.test/1', $result->schema());
        self::assertSame([], $result->codes());
        self::assertSame(1, $result->data()['failures']);
        self::assertSame(['testSomethingThatDoesNot'], array_column($result->data()['cases'], 'name'));
    }

    public function testTextModeIsHumanAndJsonModeIsSilent(): void
    {
        $text = LavaCli::run(['list'], $this->fixture('ok-app'));
        self::assertSame(ExitCode::Ok, $text->exit);
        self::assertStringContainsString('core (', $text->stdout);
        self::assertStringNotContainsString('"schema"', $text->stdout);

        $json = LavaCli::run(['list', '--json'], $this->fixture('ok-app'));
        self::assertStringNotContainsString('core (', $json->stdout);
    }

    public function testTheEnvironmentIsTheOnlyWayIn(): void
    {
        // A fixture's flags are steered by the environment, and the harness
        // strips LAVA_FEATURE_* before every child so CI can never steer a
        // fixture's packs — the same hermeticity TestApp gives in-process tests.
        $on = LavaCli::run(['routes', '--json'], $this->fixture('module-app'));
        self::assertContains('demo.quota', array_column($on->data()['routes'], 'name'));

        $off = LavaCli::run(['routes', '--json'], $this->fixture('module-app'), ['LAVA_FEATURE_DEMO_PACK' => 'off']);
        self::assertNotContains('demo.quota', array_column($off->data()['routes'], 'name'));
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/apps/' . $name;
    }

    /** A directory that is not an app, made fresh so nothing can be there. */
    private function emptyDir(): string
    {
        return $this->tempDir('lava-not-an-app-');
    }

    /**
     * A fresh temp directory, registered for cleanup.
     *
     * @param string $prefix what the directory is, for a human reading `ls /tmp`
     */
    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o755, true));
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** Writes a file, creating the directories it needs. */
    private static function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0o755, true));
        }
        self::assertNotFalse(file_put_contents($path, $contents));
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
