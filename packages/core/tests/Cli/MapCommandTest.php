<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Cli;

use Lava\Core\Boot\App;
use Lava\Core\Console\ExitCode;
use Lava\Core\Map\MapDocument;
use Lava\Core\Map\ProjectMap;
use Lava\Core\Tests\Support\LavaCli;
use Lava\Core\Tests\Support\LavaResult;
use Lava\Core\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * `lava map` through the real binary, against a COPY of a fixture app.
 *
 * The copy is the point: the command's default mode writes AGENTS.md into the
 * app directory, and a test run must never mutate a tracked fixture. Working in
 * a temp directory also happens to be the sharpest available test of the
 * document's portability — the generated file must describe the app without
 * mentioning where that app lives, and a machine-specific path leaking in would
 * show up here as the temp directory's own name.
 *
 * `--check` is the half that matters in CI, and the assertions below pin its
 * contract: it writes nothing, and it answers "no" with a non-zero exit code.
 */
final class MapCommandTest extends TestCase
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

    public function testItWritesTheDocumentAndThenChecksIt(): void
    {
        $dir = $this->copyOf('ok-app');

        $written = LavaCli::run(['map', '--json'], $dir);
        self::assertSame(ExitCode::Ok, $written->exit, $written->stderr);
        self::assertSame('lava.map/2', $written->schema());
        self::assertTrue($written->data()['written']);
        // `found` and `fresh` describe the file the command FOUND, read before
        // it acted; `written` reports what it then did. On a first write that
        // reads `found: null, fresh: false, written: true` — "there was nothing
        // current here, and now there is" — and the check below confirms the
        // document it left behind is current.
        self::assertNull($written->data()['found']);
        self::assertFalse($written->data()['fresh']);
        self::assertSame($dir . '/' . MapDocument::FILENAME, $written->data()['path']);

        $contents = (string) file_get_contents($dir . '/' . MapDocument::FILENAME);
        self::assertStringStartsWith('<!-- lava:map hash=' . $written->data()['fingerprint'] . ' -->', $contents);
        self::assertStringContainsString('## Routes (4)', $contents);
        self::assertStringContainsString('## Framework reference', $contents);

        $checked = LavaCli::run(['map', '--check', '--json'], $dir);
        self::assertSame(ExitCode::Ok, $checked->exit, $checked->stderr);
        self::assertSame('ok', $checked->status());
        self::assertTrue($checked->data()['fresh']);
        // A check that wrote something would be a different command.
        self::assertFalse($checked->data()['written']);
        self::assertSame([], $checked->codes());
    }

    public function testAChangedAppMakesTheCommittedDocumentStale(): void
    {
        $dir = $this->copyOf('ok-app');
        self::assertSame(ExitCode::Ok, LavaCli::run(['map'], $dir)->exit);

        // The app changes; the document does not. This is the drift `lava check`
        // is meant to catch, produced the way it really happens — an edit to the
        // app's own source, not a hand-edit of AGENTS.md.
        $routes = $dir . '/app/Routes.php';
        $source = (string) file_get_contents($routes);
        file_put_contents($routes, str_replace("'/users/{id:int}'", "'/members/{id:int}'", $source));

        $result = LavaCli::run(['map', '--check', '--json'], $dir);

        self::assertSame(ExitCode::Failure, $result->exit, 'a check that answers "no" must not exit 0');
        self::assertSame('failed', $result->status());
        self::assertFalse($result->data()['fresh']);
        self::assertSame(['stale_map'], $result->codes());
        self::assertSame('stale', $result->context('stale_map', 'why'));
        self::assertNotSame(
            $result->context('stale_map', 'found'),
            $result->context('stale_map', 'expected'),
        );
        // The marker is line 1, so the problem points at line 1.
        self::assertSame(1, $result->problem('stale_map')['source']['line']);
        self::assertStringStartsWith('Run: lava map', $result->problem('stale_map')['fix']);

        // And regenerating is the fix, which is what the fix hint promises.
        self::assertSame(ExitCode::Ok, LavaCli::run(['map'], $dir)->exit);
        self::assertSame(ExitCode::Ok, LavaCli::run(['map', '--check', '--json'], $dir)->exit);
    }

    public function testAnAbsentDocumentIsReportedByCheckAndWrittenByTheDefault(): void
    {
        $dir = $this->copyOf('ok-app');
        self::assertFileDoesNotExist($dir . '/' . MapDocument::FILENAME);

        $checked = LavaCli::run(['map', '--check', '--json'], $dir);

        self::assertSame(ExitCode::Failure, $checked->exit);
        self::assertSame('missing', $checked->context('stale_map', 'why'));
        // Checking must not create the file it is checking for.
        self::assertFileDoesNotExist($dir . '/' . MapDocument::FILENAME);

        $written = LavaCli::run(['map', '--json'], $dir);
        self::assertSame(ExitCode::Ok, $written->exit);
        self::assertTrue($written->data()['written']);
        self::assertFileExists($dir . '/' . MapDocument::FILENAME);
    }

    public function testTheGeneratedDocumentNamesNoMachineSpecificPath(): void
    {
        $dir = $this->copyOf('ok-app');
        self::assertSame(ExitCode::Ok, LavaCli::run(['map'], $dir)->exit);

        $contents = (string) file_get_contents($dir . '/' . MapDocument::FILENAME);

        // The app's own services are wired from `app/Services.php`, and core's
        // from `core:src/…` — never from where either happens to sit on disk.
        self::assertStringContainsString('core:src/', $contents);
        self::assertStringNotContainsString($dir, $contents);
        self::assertStringNotContainsString(sys_get_temp_dir(), $contents);
        self::assertStringNotContainsString('/home/', $contents);
        self::assertStringContainsString('| app/Services.php:', $contents);
    }

    public function testTheSameAppHashesTheSameFromAnyDirectory(): void
    {
        // The strongest statement of portability available: the app booted
        // in-process from the fixture, and the app booted by the binary from a
        // temp copy, produce the SAME fingerprint. Nothing about the location,
        // the layout, or the process leaked into the document's identity.
        $app = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $app);
        $expected = ProjectMap::of($app)->fingerprint();

        $dir = $this->copyOf('ok-app');
        $result = LavaCli::run(['map', '--check', '--json'], $dir);

        self::assertSame($expected, $result->data()['fingerprint']);
    }

    public function testTheBytesDoNotDependOnTheEnvironment(): void
    {
        $dir = $this->copyOf('ok-app');

        $dev = LavaCli::run(['map', '--env=dev'], $dir);
        self::assertSame(ExitCode::Ok, $dev->exit, $dev->stderr);
        $underDev = (string) file_get_contents($dir . '/' . MapDocument::FILENAME);

        $prod = LavaCli::run(['map', '--env=prod'], $dir);
        self::assertSame(ExitCode::Ok, $prod->exit, $prod->stderr);
        $underProd = (string) file_get_contents($dir . '/' . MapDocument::FILENAME);

        // Same bytes, so a committed document is fresh in every environment —
        // and one regenerated on a staging box is not a diff against production.
        self::assertSame($underDev, $underProd);
    }

    public function testCheckWarnsAboutAStaleMapWithoutFailingUnlessStrict(): void
    {
        $dir = $this->copyOf('ok-app');
        self::assertSame(ExitCode::Ok, LavaCli::run(['map'], $dir)->exit);
        self::mutate($dir);

        $result = LavaCli::run(['check', '--no-tests', '--json'], $dir);

        // A warning: the app is fine, its documentation is behind. A red build
        // for a stale comment would train an agent to regenerate blindly.
        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        self::assertSame('ok', $result->status());
        self::assertSame(['stale_map'], $result->codes());
        self::assertSame('warn', $result->problem('stale_map')['severity']);
        self::assertSame('map', self::section($result, 'map')['name']);
        self::assertSame('ok', self::section($result, 'map')['status']);
        self::assertSame(1, self::section($result, 'map')['problems']);

        $strict = LavaCli::run(['check', '--no-tests', '--strict', '--json'], $dir);
        self::assertSame(ExitCode::Failure, $strict->exit);
        self::assertSame('failed', self::section($strict, 'map')['status']);
    }

    public function testCheckDoesNotDemandADocumentTheAppNeverHad(): void
    {
        // `check` verifies a map you HAVE; `map --check` answers whether one
        // exists and is current. An app that chose not to ship AGENTS.md has no
        // drift to catch, and a warning nobody can act on is noise — so the two
        // commands legitimately disagree here, and both are right.
        $dir = $this->copyOf('ok-app');
        self::assertFileDoesNotExist($dir . '/' . MapDocument::FILENAME);

        $result = LavaCli::run(['check', '--no-tests', '--json'], $dir);

        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        self::assertSame([], $result->codes());
        self::assertSame('ok', self::section($result, 'map')['status']);
        self::assertSame(0, self::section($result, 'map')['problems']);
    }

    public function testTheTextViewSaysWhetherTheDocumentIsCurrent(): void
    {
        $dir = $this->copyOf('ok-app');

        $current = LavaCli::run(['map', '--check'], $dir);
        self::assertSame(ExitCode::Failure, $current->exit);
        self::assertStringContainsString('is not current', $current->stdout);
        self::assertStringContainsString('routes: 4  services: 15  features: 1  commands: 13', $current->stdout);

        $written = LavaCli::run(['map'], $dir);
        self::assertStringContainsString('Wrote ' . $dir . '/' . MapDocument::FILENAME, $written->stdout);

        $again = LavaCli::run(['map', '--check'], $dir);
        self::assertSame(ExitCode::Ok, $again->exit);
        self::assertStringContainsString('is current', $again->stdout);
    }

    /** @return array<string, mixed> */
    private static function section(LavaResult $result, string $name): array
    {
        $sections = $result->data()['sections'] ?? null;
        self::assertIsArray($sections);
        foreach ($sections as $section) {
            self::assertIsArray($section);
            if (($section['name'] ?? null) === $name) {
                return $section;
            }
        }
        self::fail("no '{$name}' section in the report");
    }

    /** Changes the app without regenerating its document. */
    private static function mutate(string $dir): void
    {
        $routes = $dir . '/app/Routes.php';
        $source = (string) file_get_contents($routes);
        file_put_contents($routes, str_replace("'/users/{id:int}'", "'/members/{id:int}'", $source));
    }

    private function copyOf(string $fixture): string
    {
        $from = dirname(__DIR__) . '/fixtures/apps/' . $fixture;
        $to = sys_get_temp_dir() . '/lava-map-' . bin2hex(random_bytes(6));
        self::assertDirectoryExists($from);
        self::copyTree($from, $to);
        $this->tempDirs[] = $to;

        return $to;
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o755, true);

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;

            if (is_dir($source)) {
                self::copyTree($source, $target);
                continue;
            }

            copy($source, $target);
        }
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
