<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Map\MapDocument;
use Lava\Core\Map\ProjectMap;
use Lava\Core\Problem\Severity;
use Lava\Core\Problem\StaleMap;
use Lava\Core\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * The map is a description of the app, and a description can be wrong.
 *
 * Two properties carry the whole design, and both are invisible in a single
 * run of `lava map`:
 *
 *  - **it describes the app it booted** — the counts and the rows come from the
 *    registries, not from a parallel reading of the files, so a route the router
 *    does not have cannot appear in the document;
 *  - **it describes only the app** — no environment, no machine, no timestamp.
 *    That is what makes the file safe to commit and the fingerprint worth
 *    comparing, and it is the property a regression would break silently: a map
 *    that leaked the environment would look perfectly correct until the first
 *    deploy that changed nothing reported it stale.
 */
final class ProjectMapTest extends TestCase
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

    public function testTheCountsAreTheAppsOwnRegistries(): void
    {
        $app = self::okApp();
        $map = ProjectMap::of($app);

        // The invariant, stated against the app rather than against a number: a
        // map that disagreed with the registries is the failure this whole
        // command exists to prevent.
        self::assertSame([
            'routes' => count($app->router->routes()),
            'services' => count($app->container->ids()),
            'features' => count($app->features->definitions->names()),
            'commands' => count($app->commands()->all()),
            'middleware' => count($app->globalMiddleware),
            'modules' => count($app->moduleRefs),
            'env' => count($app->envVars()),
        ], $map->counts());

        // And the concrete numbers, so a fixture that quietly lost a route is a
        // failure here rather than two counts that agree on being wrong.
        self::assertSame(4, $map->counts()['routes']);
        self::assertSame(1, $map->counts()['features']);
        self::assertSame(1, $map->counts()['middleware']);
    }

    public function testTheRoutesSectionDescribesWhatTheRouterHolds(): void
    {
        $app = self::okApp();
        $map = ProjectMap::of($app);

        self::assertSame(
            array_map(static fn (object $route): string => $route->name, $app->router->routes()),
            array_column($map->routes, 'name'),
            'the routes section must be the router\'s own list, in the router\'s own order',
        );

        foreach ($map->routes as $route) {
            // The handler column is the router's OWN description of the plan it
            // compiled. Anything else would be a second opinion about the app.
            self::assertNotSame('', $route['handler']);
            self::assertNotSame('', $route['path']);
            self::assertNotEmpty($route['methods']);
        }
    }

    public function testTheFingerprintIsStableAcrossBuilds(): void
    {
        $app = self::okApp();

        self::assertSame(
            ProjectMap::of($app)->fingerprint(),
            ProjectMap::of($app)->fingerprint(),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', ProjectMap::of($app)->fingerprint());
    }

    public function testTheFingerprintDoesNotMoveWithTheEnvironment(): void
    {
        // The property that makes the file committable. ok-app's flag is set to
        // `on` in config/features.php and its .env says prod; neither may appear
        // in the fingerprint, because a map is a list of DECLARATIONS. If this
        // ever fails, every deploy of an app that changed nothing reports a
        // stale map — and the fix is to remove whatever resolved state leaked in.
        $dev = self::boot('ok-app', ['LAVA_ENV' => 'dev']);
        $prod = self::boot('ok-app', ['LAVA_ENV' => 'prod']);

        self::assertNotSame($dev->env, $prod->env, 'the two boots must really be in different environments');
        self::assertSame(
            ProjectMap::of($dev)->fingerprint(),
            ProjectMap::of($prod)->fingerprint(),
        );
        self::assertSame(ProjectMap::of($dev)->sections(), ProjectMap::of($prod)->sections());
    }

    public function testEveryPathInTheDocumentIsPortable(): void
    {
        $map = ProjectMap::of(self::okApp());
        $paths = array_merge(
            array_column($map->services, 'at'),
            array_column($map->modules, 'declared_at'),
            $map->config,
        );

        self::assertNotEmpty($paths);
        foreach ($paths as $path) {
            // A leading `/` would make the document machine-specific, and a `..`
            // would make it depend on where it was generated from.
            self::assertStringNotContainsString(sys_get_temp_dir(), $path);
            self::assertStringNotContainsString('/home/', $path);
            self::assertStringNotContainsString('\\', $path);
            self::assertStringStartsNotWith('/', $path);
            self::assertStringStartsNotWith('../', $path);
        }
    }

    public function testDependencyCodeRendersTheSameInstalledOrCheckedOut(): void
    {
        // THE reason the canonicalization exists: the same core file lives at
        // `vendor/lava/core/…` in an installed app and at `packages/core/…` in
        // this checkout. An app's committed AGENTS.md must not care which.
        self::assertSame(
            'core:src/Boot/Kernel.php',
            ProjectMap::relative('/srv/site/vendor/lava/core/src/Boot/Kernel.php', '/srv/site'),
        );
        self::assertSame(
            'core:src/Boot/Kernel.php',
            ProjectMap::relative('/home/five/projects/LavaPHP/packages/core/src/Boot/Kernel.php', '/srv/site'),
        );
    }

    public function testTheLastVendorMarkerWins(): void
    {
        // A package that vendors its own dependencies nests a second `vendor/`.
        // Two readings are structurally legal here — `thing:` with the whole
        // `vendor/other/pkg/src/X.php` as the rest, or `pkg:` with `src/X.php` —
        // and the document must take the second: the inner marker is the one
        // that names the file's real owner.
        self::assertSame(
            'pkg:src/X.php',
            ProjectMap::relative('/srv/site/vendor/acme/thing/vendor/other/pkg/src/X.php', '/srv/site'),
        );
        self::assertNotSame(
            'thing:vendor/other/pkg/src/X.php',
            ProjectMap::relative('/srv/site/vendor/acme/thing/vendor/other/pkg/src/X.php', '/srv/site'),
            'the outer marker must not swallow the inner package',
        );
    }

    public function testAnAppsOwnFilesAreRelativeToTheAppRoot(): void
    {
        self::assertSame('app/Services.php', ProjectMap::relative('/srv/site/app/Services.php', '/srv/site'));
        self::assertSame('config/app.php', ProjectMap::relative('/srv/site/config/app.php', '/srv/site'));
        self::assertSame('.', ProjectMap::relative('/srv/site', '/srv/site'));
        self::assertSame('.', ProjectMap::relative('/srv/site/', '/srv/site'));
    }

    public function testAnAppLivingUnderAPackagesDirectoryStillOwnsItsOwnFiles(): void
    {
        // The regression this guards, and it was real: this repo's fixtures live
        // under `packages/core/tests/fixtures/apps/`, so a rule that looked for
        // the `packages` marker anywhere rendered `app/Services.php` as
        // `core:tests/fixtures/apps/ok-app/app/Services.php`. The same app booted
        // from a temp copy then hashed differently, which is exactly what a
        // fingerprint must never do.
        self::assertSame(
            'app/Services.php',
            ProjectMap::relative('/srv/packages/site/app/Services.php', '/srv/packages/site'),
        );
        self::assertSame(
            'app/Http/UserController.php',
            ProjectMap::relative('/srv/vendor/company/site/app/Http/UserController.php', '/srv/vendor/company/site'),
        );
        // A deeper directory the APP named `packages` is the app's too — only the
        // first segment can turn the app's own tree into a dependency's.
        self::assertSame(
            'app/packages/thing/file.php',
            ProjectMap::relative('/srv/site/app/packages/thing/file.php', '/srv/site'),
        );
        // But dependency code the app installed INTO itself is still that.
        self::assertSame(
            'core:src/Boot/Kernel.php',
            ProjectMap::relative('/srv/site/vendor/lava/core/src/Boot/Kernel.php', '/srv/site'),
        );
        self::assertSame(
            'core:src/Boot/Kernel.php',
            ProjectMap::relative('/srv/site/packages/core/src/Boot/Kernel.php', '/srv/site'),
        );
    }

    public function testAPathOutsideTheAppGetsARelativeChain(): void
    {
        self::assertSame(
            '../../other/place/file.php',
            ProjectMap::relative('/other/place/file.php', '/srv/site'),
        );
        // The shared prefix is consumed, and the basename is never consumed —
        // a relative path that ate its own last segment would point at nothing.
        self::assertSame('../other/file.php', ProjectMap::relative('/srv/other/file.php', '/srv/site'));
        self::assertSame('../sitex/file.php', ProjectMap::relative('/srv/sitex/file.php', '/srv/site'));
    }

    public function testAMissingDocumentIsMissing(): void
    {
        $dir = $this->tempDir();
        $problem = ProjectMap::of(self::okApp())->staleness(MapDocument::at($dir));

        self::assertInstanceOf(StaleMap::class, $problem);
        self::assertSame('stale_map', $problem->code());
        self::assertSame(Severity::Warn, $problem->severity());
        self::assertSame('missing', $problem->context['why']);
        self::assertSame($dir . '/' . MapDocument::FILENAME, $problem->context['path']);
        self::assertStringStartsWith('Run: lava map', $problem->fix);
    }

    public function testADocumentFromAnEarlierAppIsStale(): void
    {
        $dir = $this->tempDir();
        $map = ProjectMap::of(self::okApp());
        file_put_contents($dir . '/' . MapDocument::FILENAME, MapDocument::marker('0000000000000000') . "# old\n");

        $problem = $map->staleness(MapDocument::at($dir));

        self::assertInstanceOf(StaleMap::class, $problem);
        self::assertSame('stale', $problem->context['why']);
        self::assertSame('0000000000000000', $problem->context['found']);
        self::assertSame($map->fingerprint(), $problem->context['expected']);
        // The marker is on line 1, so line 1 is the line that is wrong.
        self::assertSame(1, $problem->source?->line);
    }

    public function testAFileWithNoMarkerAtAllIsStaleRatherThanMissing(): void
    {
        // It exists and it is wrong. Calling it 'missing' would tell the reader
        // to create a file that is right there in front of them.
        $dir = $this->tempDir();
        file_put_contents($dir . '/' . MapDocument::FILENAME, "# AGENTS.md\n\nHand-written, no marker.\n");

        $problem = ProjectMap::of(self::okApp())->staleness(MapDocument::at($dir));

        self::assertInstanceOf(StaleMap::class, $problem);
        self::assertSame('stale', $problem->context['why']);
        self::assertSame('(no marker)', $problem->context['found']);
    }

    public function testACurrentDocumentIsNoProblem(): void
    {
        $dir = $this->tempDir();
        $map = ProjectMap::of(self::okApp());
        file_put_contents($dir . '/' . MapDocument::FILENAME, MapDocument::marker($map->fingerprint()) . $map->markdown());

        self::assertNull($map->staleness(MapDocument::at($dir)));
    }

    public function testTheDocumentRendersItsOwnHashAsItsFirstLine(): void
    {
        // The marker has to be line 1 and nothing else — MapDocument matches it
        // with \A. A hash a reader could reach by scrolling is a hash the
        // comparison could find in a fenced example instead of in the marker.
        $dir = $this->tempDir();
        $map = ProjectMap::of(self::okApp());
        $document = MapDocument::at($dir);

        self::assertTrue($document->write($map));

        $written = (string) file_get_contents($dir . '/' . MapDocument::FILENAME);
        self::assertStringStartsWith(MapDocument::marker($map->fingerprint()), $written);
        self::assertSame(1, substr_count($written, 'lava:map hash='));
        self::assertTrue(MapDocument::at($dir)->isFresh($map->fingerprint()));
    }

    private static function okApp(): App
    {
        return self::boot('ok-app');
    }

    /** @param array<string, string> $env */
    private static function boot(string $fixture, array $env = []): App
    {
        $app = TestApp::bootFixture($fixture, $env);
        self::assertInstanceOf(App::class, $app, "the {$fixture} fixture must boot: " . self::describe($app));

        return $app;
    }

    private static function describe(App|BootFailure $app): string
    {
        if ($app instanceof App) {
            return 'it booted';
        }

        return implode(', ', array_map(
            static fn ($problem): string => $problem->code(),
            $app->problems->problems(),
        ));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/lava-map-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
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
