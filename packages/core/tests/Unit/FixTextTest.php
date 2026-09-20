<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\Command;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\Commands\MapCommand;
use Lava\Core\Map\FrameworkReference;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;
use Lava\Core\Testing\TestConsole;
use Lava\Core\Tests\Support\PhpSnippet;
use PHPUnit\Framework\TestCase;

/**
 * Every problem's `fix` is checked against the framework it tells you to use.
 *
 * The fix is the fourth pillar — one error, one round trip — and across three
 * rounds of outside builds it was the pillar that broke most: six times a
 * printed fix was wrong or unfollowable, and following it produced a second
 * error. A fix naming a method that no longer exists is worse than no fix,
 * because it is the framework telling someone to write code that cannot run.
 *
 * So the promise is tested rather than asserted. Every problem class is
 * constructed here through its real named constructors, and its fix must:
 *
 *  - be imperative prose, non-empty, and a sentence;
 *  - name only commands that exist, with flags those commands declare — a
 *    `Run: lava map --chekc` fails here rather than in the reader's terminal;
 *  - actually run, for the safe idempotent subset, against a green app;
 *  - name only `composer require` packages this repository publishes;
 *  - name only framework classes and methods that exist;
 *  - name only artifact paths the framework actually reads, taken from
 *    {@see FrameworkReference} and the installed packs rather than a second
 *    list kept here.
 *
 * **The accounting is the guard.** Every class under `packages/*​/src/Problem/`
 * must appear in the fixture table or in {@see NO_FIXTURE} with a reason, so a
 * new problem cannot ship without someone stating what its fix looks like.
 *
 * `lava check` is validated but not executed: it shells out to PHPUnit, and a
 * unit test that runs a test runner is a test of the runner.
 */
final class FixTextTest extends TestCase
{
    /** Commands safe to execute here: idempotent, no app suite, no network. */
    private const EXECUTABLE = ['map', 'routes', 'api', 'about', 'services', 'list', 'env', 'features'];

    /**
     * Problem classes with no fixture, and why.
     *
     * @var array<class-string, string>
     */
    private const NO_FIXTURE = [];

    public function testEveryProblemClassHasAFixtureOrASatedReason(): void
    {
        $root = self::skipUnlessThePackagesAreHere();

        $covered = array_keys(self::fixtures());
        $missing = [];
        foreach (self::problemClasses($root) as $class) {
            if (in_array($class, $covered, true) || isset(self::NO_FIXTURE[$class])) {
                continue;
            }
            $missing[] = $class;
        }

        self::assertSame([], $missing, implode("\n", [
            'these problem classes have no fixture, so nothing checks that their fix works:',
            implode("\n", array_map(static fn (string $class): string => '  ' . $class, $missing)),
            'add one to FixTextTest::fixtures(), or name the class in NO_FIXTURE with the reason.',
        ]));

        foreach (self::NO_FIXTURE as $class => $reason) {
            self::assertMatchesRegularExpression('/\w{10,}/', $reason, "the exclusion for {$class} must say why");
        }
    }

    public function testEveryFixIsImperativeProse(): void
    {
        foreach (self::fixtures() as $class => $problems) {
            foreach ($problems as $problem) {
                $fix = $problem->fix;
                self::assertNotSame('', trim($fix), "{$class} has an empty fix");
                // Not terminal punctuation: a fix that ends in the command to
                // run (`… php vendor/bin/phpunit`) is finished prose, and the
                // contract promises an imperative, not a full stop.
                self::assertMatchesRegularExpression(
                    '/^[A-Z`\'"]/u',
                    trim($fix),
                    "{$class}'s fix does not start like a sentence: {$fix}",
                );
            }
        }
    }

    public function testEveryRunnableFixNamesACommandWithFlagsItDeclares(): void
    {
        $registry = self::registry();
        $checked = 0;

        foreach (self::fixtures() as $class => $problems) {
            foreach ($problems as $problem) {
                foreach (self::lavaCommands($problem->fix) as [$name, $flags]) {
                    $checked++;
                    $command = $registry->get($name);
                    self::assertInstanceOf(
                        Command::class,
                        $command,
                        "{$class}'s fix says `lava {$name}`, which is not a command",
                    );

                    $accepted = array_merge($command->flags(), Command::UNIVERSAL_FLAGS);
                    foreach ($flags as $flag) {
                        self::assertContains(
                            $flag,
                            $accepted,
                            "{$class}'s fix passes --{$flag} to `lava {$name}`, which does not accept it",
                        );
                    }
                }
            }
        }

        self::assertGreaterThan(5, $checked, 'no runnable fixes were found — is the reader still right?');
    }

    /**
     * The safe subset is run for real: a fix that says `Run: lava map` has to
     * exit 0 on an app that is otherwise green, or it is not a fix.
     */
    public function testTheSafeRunnableFixesActuallyRun(): void
    {
        $app = self::greenApp();
        $ran = 0;

        foreach (self::fixtures() as $class => $problems) {
            foreach ($problems as $problem) {
                foreach (self::lavaCommands($problem->fix) as [$name, $flags]) {
                    if (!in_array($name, self::EXECUTABLE, true)) {
                        continue;
                    }
                    $ran++;
                    $arguments = array_merge([$name], array_map(static fn (string $flag): string => '--' . $flag, $flags));
                    $result = (new TestConsole($app))->run(...$arguments);
                    self::assertSame(
                        0,
                        $result->exitCode(),
                        "{$class}'s fix says `lava " . implode(' ', $arguments) . "`, which exits "
                            . $result->exitCode() . " on a green app:\n" . $result->output() . $result->errors(),
                    );
                }
            }
        }

        self::assertGreaterThan(2, $ran, 'no executable fix was executed — is EXECUTABLE still right?');
    }

    public function testEveryComposerRequireFixNamesAPackageThisRepositoryPublishes(): void
    {
        $root = self::skipUnlessThePackagesAreHere();

        $published = [];
        foreach (glob($root . '/packages/*/composer.json') ?: [] as $manifest) {
            $name = json_decode((string) file_get_contents($manifest), true);
            if (is_array($name) && is_string($name['name'] ?? null)) {
                $published[] = $name['name'];
            }
        }

        foreach (self::fixtures() as $class => $problems) {
            foreach ($problems as $problem) {
                preg_match_all('/composer require ([a-z0-9\-]+\/[a-z0-9\-]+)/', $problem->fix, $matches);
                foreach ($matches[1] as $package) {
                    self::assertContains(
                        $package,
                        $published,
                        "{$class}'s fix says `composer require {$package}`, which this repository does not publish",
                    );
                }
            }
        }
    }

    /**
     * Every framework symbol a fix names — a written-out class, and a method
     * called on one — exists.
     *
     * A fix writes short names the way a reader would type them (`Flag::off()`,
     * `Schema::dropIndex()`), so they are resolved against the index `lava api`
     * publishes rather than only when spelled in full. This is the check that
     * catches the rot the reviews found: a fix telling someone to call a method
     * that was renamed.
     */
    public function testEveryFrameworkSymbolAFixNamesExists(): void
    {
        $shortNames = self::indexedShortNames();
        $checked = 0;

        foreach (self::fixtures() as $class => $problems) {
            foreach ($problems as $problem) {
                foreach (PhpSnippet::lavaNames($problem->fix) as $name) {
                    $checked++;
                    self::assertTrue(
                        class_exists($name) || interface_exists($name) || enum_exists($name),
                        "{$class}'s fix names {$name}, which does not exist: {$problem->fix}",
                    );
                }

                foreach (PhpSnippet::staticCalls($problem->fix) as [$written, $method]) {
                    $target = PhpSnippet::resolve($written, $shortNames);
                    if ($target === null) {
                        continue;
                    }
                    $checked++;
                    self::assertTrue(
                        method_exists($target, $method),
                        "{$class}'s fix says {$target}::{$method}(), which does not exist: {$problem->fix}",
                    );
                }
            }
        }

        self::assertGreaterThan(5, $checked, 'no framework symbols were found in any fix — is the reader still right?');
    }

    public function testEveryArtifactPathAFixNamesIsOneTheFrameworkReads(): void
    {
        $known = self::artifactPaths(self::skipUnlessThePackagesAreHere());
        $checked = 0;

        foreach (self::fixtures() as $class => $problems) {
            foreach ($problems as $problem) {
                // Relative paths only: those are the artifacts the reader is
                // told to open. An absolute path is the framework quoting where
                // something already is — `source` carries that, and its tail
                // would otherwise read as an artifact (`/srv/site/app/…`).
                preg_match_all('#(?<![\w/])((?:app|config|tests|public|database)/[A-Za-z0-9_./\-]+)#', $problem->fix, $matches);
                foreach ($matches[1] as $path) {
                    $path = rtrim($path, '.,');
                    // A fix may cite a line (`app/Services.php:63`); the file is
                    // what has to exist as an artifact.
                    $path = preg_replace('/:\d+$/', '', $path) ?? $path;
                    if (str_contains($path, '<') || str_contains($path, '…')) {
                        continue; // a placeholder, e.g. app/Http/<YourController>.php
                    }
                    $checked++;
                    self::assertTrue(
                        self::isKnownArtifact($path, $known),
                        "{$class}'s fix names {$path}, which is not an artifact the framework reads: {$problem->fix}",
                    );
                }
            }
        }

        self::assertGreaterThan(5, $checked, 'no artifact paths were found in any fix — is the reader still right?');
    }

    /**
     * Every problem class, constructed the way the framework constructs it.
     *
     * @return array<class-string, list<LavaProblem>>
     */
    private static function fixtures(): array
    {
        // A realistic absolute path: a fix quotes it back, and `/app/app/…`
        // would make the artifact check read `app/app/Services.php`.
        $source = SourceLocation::of('/srv/site/app/Routes.php', 12);
        $other = SourceLocation::of('/srv/site/app/Services.php', 40);
        $throwable = new \RuntimeException('boom');
        $command = new MapCommand();
        $ref = ModuleRef::of(\Lava\Db\DbModule::class, package: 'lavaphp/db', feature: 'db');

        $fixtures = [
            \Lava\Core\Problem\BadHandler::class => [
                \Lava\Core\Problem\BadHandler::of('App\Http\Posts::show', 'it is not callable', 'Give it a public method.'),
                \Lava\Core\Problem\BadHandler::routeHasNone('posts.show', '/posts/{slug:str}', $source),
            ],
            \Lava\Core\Problem\BadMiddleware::class => [
                \Lava\Core\Problem\BadMiddleware::missing('App\Http\Timing', 'app/Middleware.php'),
                \Lava\Core\Problem\BadMiddleware::notPsr15('App\Http\Timing', 'app/Middleware.php'),
            ],
            \Lava\Core\Problem\BadRedirect::class => [
                \Lava\Core\Problem\BadRedirect::status('posts.old', 200, $source),
                \Lava\Core\Problem\BadRedirect::unknownTarget('posts.old', 'posts.shwo', 'posts.show', $source),
                \Lava\Core\Problem\BadRedirect::targetNotGet('posts.old', 'posts.store', ['POST'], $source),
                \Lava\Core\Problem\BadRedirect::targetIsRedirect('posts.old', 'posts.older', 'posts.show', $source),
                \Lava\Core\Problem\BadRedirect::shadowsTarget('posts.old', 'posts.show', '/posts/a', true, $source),
                \Lava\Core\Problem\BadRedirect::unreachable('posts.old', 'posts.show', '/posts/a', $source),
                \Lava\Core\Problem\BadRedirect::loops('posts.old', 'posts.show', '/posts/a', $source),
                \Lava\Core\Problem\BadRedirect::param('posts.old', 'posts.show', 'slug', 'str', ['id' => 'int'], $source),
            ],
            \Lava\Core\Problem\BadRequestPath::class => [
                \Lava\Core\Problem\BadRequestPath::dotSegment('/docs/../secret.txt'),
                \Lava\Core\Problem\BadRequestPath::controlByte("/docs/a\0b"),
            ],
            \Lava\Core\Problem\BadReplacement::class => [
                \Lava\Core\Problem\BadReplacement::unregistered('App\Nowhere'),
                \Lava\Core\Problem\BadReplacement::notKeyedById(0, 'App\Greeter'),
                \Lava\Core\Problem\BadReplacement::wrongType('App\Greeter', 'string'),
            ],
            \Lava\Core\Problem\BadRoutePattern::class => [
                \Lava\Core\Problem\BadRoutePattern::of('/posts/{slug}', "param 'slug' has no explicit type", 'Write {name:type}.', $source),
            ],
            \Lava\Core\Problem\BadTestReport::class => [
                \Lava\Core\Problem\BadTestReport::empty(1, ''),
                \Lava\Core\Problem\BadTestReport::malformed('no JSON object in the output'),
            ],
            \Lava\Core\Problem\BadUsage::class => [
                \Lava\Core\Problem\BadUsage::missing('selector', 'lava describe <selector>'),
                \Lava\Core\Problem\BadUsage::unknownFlag('strct', 'check', ['strict', 'quick']),
                \Lava\Core\Problem\BadUsage::invalid('port', 'abc', 'a port number', 'lava serve --port=8000'),
                \Lava\Core\Problem\BadUsage::invalidArgument('id', 'abc', 'an integer', 'lava db:rollback <id>'),
            ],
            \Lava\Core\Problem\CircularService::class => [
                \Lava\Core\Problem\CircularService::of(['App\A', 'App\B', 'App\A']),
            ],
            \Lava\Core\Problem\DuplicateCommand::class => [
                \Lava\Core\Problem\DuplicateCommand::of('map', $command, $command),
            ],
            \Lava\Core\Problem\DuplicateFeature::class => [
                \Lava\Core\Problem\DuplicateFeature::of('beta', 'config/features.php:10', 'config/features.php:14'),
            ],
            \Lava\Core\Problem\DuplicateRouteName::class => [
                \Lava\Core\Problem\DuplicateRouteName::of('posts.show', $source, $other),
            ],
            \Lava\Core\Problem\DuplicateService::class => [
                \Lava\Core\Problem\DuplicateService::of('App\Greeter', $source, $other),
            ],
            \Lava\Core\Problem\IncompleteTestReport::class => [
                \Lava\Core\Problem\IncompleteTestReport::of(0, 0, 3),
            ],
            \Lava\Core\Problem\InvalidCommandName::class => [
                \Lava\Core\Problem\InvalidCommandName::of($command, 'app/Commands.php', ['map']),
            ],
            \Lava\Core\Problem\InvalidConfig::class => [
                \Lava\Core\Problem\InvalidConfig::badType('app.name', 'string', 'int', 'config/app.php'),
                \Lava\Core\Problem\InvalidConfig::outOfRange('logging.level', 9, '0 to 7', 'config/logging.php'),
                \Lava\Core\Problem\InvalidConfig::notAnArray('config/app.php', 'app', 'string'),
                \Lava\Core\Problem\InvalidConfig::threw('config/app.php', 'app', $throwable),
                \Lava\Core\Problem\InvalidConfig::notAStringKey('config/app.php', 'app', 0),
                \Lava\Core\Problem\InvalidConfig::wrongService('App\Clock', 'Psr\Clock\ClockInterface', 'a string'),
            ],
            \Lava\Core\Problem\InvalidEnvFile::class => [
                \Lava\Core\Problem\InvalidEnvFile::of('/app/config/.env', 4, 'NOT A LINE'),
            ],
            \Lava\Core\Problem\InvalidFeatureName::class => [
                \Lava\Core\Problem\InvalidFeatureName::of('Beta-UI'),
            ],
            \Lava\Core\Problem\InvalidFlagValue::class => [
                \Lava\Core\Problem\InvalidFlagValue::of('rollout:101', 'rollout percentage must be between 0 and 100'),
            ],
            \Lava\Core\Problem\InvalidGating::class => [
                \Lava\Core\Problem\InvalidGating::of('db', 'rollout:50', 'lavaphp/db'),
            ],
            \Lava\Core\Problem\MalformedBody::class => [
                \Lava\Core\Problem\MalformedBody::unparsable('application/json', 'Syntax error'),
                \Lava\Core\Problem\MalformedBody::notAnObject('application/json', 'string'),
            ],
            \Lava\Core\Problem\MethodNotAllowed::class => [
                \Lava\Core\Problem\MethodNotAllowed::of('POST', '/posts', ['GET', 'HEAD']),
            ],
            \Lava\Core\Problem\MissingEntryPoint::class => [
                \Lava\Core\Problem\MissingEntryPoint::in('/app'),
            ],
            \Lava\Core\Problem\MissingEnvVar::class => [
                \Lava\Core\Problem\MissingEnvVar::of('DATABASE_URL', 'app/Services.php'),
            ],
            \Lava\Core\Problem\MissingPack::class => [
                \Lava\Core\Problem\MissingPack::of($ref),
            ],
            \Lava\Core\Problem\MissingTestRunner::class => [
                \Lava\Core\Problem\MissingTestRunner::in('/app'),
            ],
            \Lava\Core\Problem\ModuleMismatch::class => [
                \Lava\Core\Problem\ModuleMismatch::of($ref, 'lavaphp/database', 'database'),
            ],
            \Lava\Core\Problem\NotAnApp::class => [
                \Lava\Core\Problem\NotAnApp::at('/tmp/nope', ['app', 'config', 'public/index.php']),
            ],
            \Lava\Core\Problem\RequestTooLarge::class => [
                \Lava\Core\Problem\RequestTooLarge::form('multipart/form-data', 4412, 1024),
            ],
            \Lava\Core\Problem\RouteNotFound::class => [
                \Lava\Core\Problem\RouteNotFound::of('GET', '/nope'),
            ],
            \Lava\Core\Problem\ServiceNotRegistered::class => [
                \Lava\Core\Problem\ServiceNotRegistered::of('App\Greeter', 'app/Routes.php', 'the handler needs it as an injected parameter'),
                \Lava\Core\Problem\ServiceNotRegistered::of('App\Gretter', 'app/Routes.php', null, ['App\Greeter']),
            ],
            \Lava\Core\Problem\StaleMap::class => [
                \Lava\Core\Problem\StaleMap::missing('/app/AGENTS.md'),
                \Lava\Core\Problem\StaleMap::stale('/app/AGENTS.md', 'aaaa', 'bbbb'),
                \Lava\Core\Problem\StaleMap::unwritable('/app/AGENTS.md', '/app'),
            ],
            \Lava\Core\Problem\UnexpectedFailure::class => [
                \Lava\Core\Problem\UnexpectedFailure::of('Lava\Core\Boot\Steps\WireModules', $throwable),
                \Lava\Core\Problem\UnexpectedFailure::inCommand('map', $throwable),
                \Lava\Core\Problem\UnexpectedFailure::inRequest(new \Nyholm\Psr7\ServerRequest('GET', '/posts'), $throwable),
            ],
            \Lava\Core\Problem\UnknownCommand::class => [
                \Lava\Core\Problem\UnknownCommand::of('mpa', 'map'),
                \Lava\Core\Problem\UnknownCommand::of('nonsense', null),
            ],
            \Lava\Core\Problem\UnknownEnvBranch::class => [
                \Lava\Core\Problem\UnknownEnvBranch::of('beta', 'staging', ['dev', 'prod']),
            ],
            \Lava\Core\Problem\UnknownFeature::class => [
                \Lava\Core\Problem\UnknownFeature::of('beta_u', 'beta_ui'),
            ],
            \Lava\Core\Problem\UnknownRoute::class => [
                \Lava\Core\Problem\UnknownRoute::of('posts.shwo', 'posts.show'),
            ],
            \Lava\Core\Problem\UnknownSelector::class => [
                \Lava\Core\Problem\UnknownSelector::of('rutes', 'routes', ['routes' => ['posts.show'], 'services' => [], 'flags' => [], 'env' => [], 'commands' => ['routes']]),
            ],
        ];

        return $fixtures + self::packFixtures();
    }

    /**
     * The installed packs' problems. A pack that is not installed contributes
     * nothing and is not demanded by the accounting guard either, because its
     * files are not on disk to be counted.
     *
     * @return array<class-string, list<LavaProblem>>
     */
    private static function packFixtures(): array
    {
        $fixtures = [];
        $source = SourceLocation::of('/app/database/migrations/001_posts.php', 8);
        $throwable = new \RuntimeException('boom');

        if (class_exists(\Lava\Db\Problem\BadQuery::class)) {
            $fixtures[\Lava\Db\Problem\BadQuery::class] = [
                \Lava\Db\Problem\BadQuery::notAColumn('count(*)', 'select'),
                \Lava\Db\Problem\BadQuery::nullComparison('published_at'),
                \Lava\Db\Problem\BadQuery::emptyIn('id'),
                \Lava\Db\Problem\BadQuery::unbounded('update', 'posts'),
                \Lava\Db\Problem\BadQuery::sameResultName(
                    ['column' => 'posts.id', 'alias' => null, 'argument' => 0, 'name' => 'id'],
                    ['column' => 'users.id', 'alias' => null, 'argument' => 1, 'name' => 'id'],
                    ['posts.id', 'users.id'],
                    ['id'],
                ),
            ];
            $fixtures[\Lava\Db\Problem\BadSchema::class] = [
                \Lava\Db\Problem\BadSchema::emptyTableName(),
                \Lava\Db\Problem\BadSchema::duplicateColumn('posts', 'slug'),
                \Lava\Db\Problem\BadSchema::unknownColumn('posts', 'slog', 'index()', ['slug', 'title']),
                \Lava\Db\Problem\BadSchema::unknownIndex('posts', 'posts_slog_index', ['posts_slug_index']),
            ];
            $fixtures[\Lava\Db\Problem\DbConnectionFailed::class] = [
                \Lava\Db\Problem\DbConnectionFailed::of('sqlite:/nope/app.sqlite', new \PDOException('unable to open database file')),
            ];
            $fixtures[\Lava\Db\Problem\DbNotConfigured::class] = [\Lava\Db\Problem\DbNotConfigured::of()];
            $fixtures[\Lava\Db\Problem\InvalidMigrationFile::class] = [
                \Lava\Db\Problem\InvalidMigrationFile::notAMigration('/app/database/migrations/001_posts.php', 'array'),
                \Lava\Db\Problem\InvalidMigrationFile::badName('/app/database/migrations/posts.php'),
                \Lava\Db\Problem\InvalidMigrationFile::threw('/app/database/migrations/001_posts.php', $throwable),
                \Lava\Db\Problem\InvalidMigrationFile::alreadyExists('/app/database/migrations/001_posts.php'),
                \Lava\Db\Problem\InvalidMigrationFile::unwritable('/app/database/migrations', true),
            ];
            $fixtures[\Lava\Db\Problem\MigrationFailed::class] = [
                \Lava\Db\Problem\MigrationFailed::of('001_posts', 'up', $throwable, $source),
                \Lava\Db\Problem\MigrationFailed::missingFile('001_posts', '/app/database/migrations', 2),
            ];
            $fixtures[\Lava\Db\Problem\QueryFailed::class] = [
                \Lava\Db\Problem\QueryFailed::of(new \Lava\Db\Sql\Compiled('select * from posts', []), new \PDOException('no such table: posts')),
            ];
            $fixtures[\Lava\Db\Problem\UnsupportedDialect::class] = [
                \Lava\Db\Problem\UnsupportedDialect::of('oracle', ['sqlite', 'mysql', 'pgsql']),
            ];
        }

        if (class_exists(\Lava\Events\Problem\BadListener::class)) {
            $fixtures[\Lava\Events\Problem\BadListener::class] = [
                \Lava\Events\Problem\BadListener::unknownEvent('App\Events\Nope', '/app/app/Listeners.php'),
                \Lava\Events\Problem\BadListener::notInvokable('App\Listeners\Log', 'App\Events\Posted', 'string', '/app/app/Listeners.php'),
                \Lava\Events\Problem\BadListener::cannotTake('App\Listeners\Log', 'App\Events\Posted', 'its parameter is typed int', '/app/app/Listeners.php'),
            ];
            $fixtures[\Lava\Events\Problem\FactoryListener::class] = [
                \Lava\Events\Problem\FactoryListener::of('App\Listeners\Log', 'App\Events\Posted', 'app/Services.php:63', '/app/app/Listeners.php'),
            ];
            $fixtures[\Lava\Events\Problem\InvalidListenersFile::class] = [
                \Lava\Events\Problem\InvalidListenersFile::notAMap('/app/app/Listeners.php', 'string'),
                \Lava\Events\Problem\InvalidListenersFile::notAnEvent('/app/app/Listeners.php', 0),
                \Lava\Events\Problem\InvalidListenersFile::notListeners('/app/app/Listeners.php', 'App\Events\Posted', 'int'),
                \Lava\Events\Problem\InvalidListenersFile::unreadable('/app/app/Listeners.php', new \ParseError('unclosed [')),
            ];
            $fixtures[\Lava\Events\Problem\ListenerOrderConflict::class] = [
                \Lava\Events\Problem\ListenerOrderConflict::of(
                    'App\Listeners\Audit',
                    ['first' => ['App\Events\Posted'], 'last' => ['App\Events\Commented']],
                    '/app/app/Listeners.php',
                ),
            ];
        }

        if (class_exists(\Lava\HttpClient\Problem\BadJsonResponse::class)) {
            $request = new \Nyholm\Psr7\Request('GET', 'https://api.example/posts');
            $fixtures[\Lava\HttpClient\Problem\BadJsonResponse::class] = [
                \Lava\HttpClient\Problem\BadJsonResponse::of('GET', 'https://api.example/posts', 200, '<html>', 'Syntax error'),
            ];
            $fixtures[\Lava\HttpClient\Problem\BadRequestUrl::class] = [
                \Lava\HttpClient\Problem\BadRequestUrl::of($request, 'the scheme must be http or https'),
            ];
            $fixtures[\Lava\HttpClient\Problem\TransportFailed::class] = [
                \Lava\HttpClient\Problem\TransportFailed::of($request, 'could not resolve host'),
            ];
            $fixtures[\Lava\HttpClient\Problem\UnencodableJsonBody::class] = [
                \Lava\HttpClient\Problem\UnencodableJsonBody::of('POST', 'https://api.example/posts', 'a resource cannot be encoded'),
            ];
            $fixtures[\Lava\HttpClient\Problem\ResponseTooLarge::class] = [
                \Lava\HttpClient\Problem\ResponseTooLarge::of($request, 8_388_608, 8_400_000),
            ];
            $fixtures[\Lava\HttpClient\Problem\UnsendableRequest::class] = [
                \Lava\HttpClient\Problem\UnsendableRequest::method($request, "GET\r\nX: y", 'it is not a method name'),
                \Lava\HttpClient\Problem\UnsendableRequest::header($request, 'X-Note'),
            ];
            $fixtures[\Lava\HttpClient\Problem\UnexpectedStatus::class] = [
                \Lava\HttpClient\Problem\UnexpectedStatus::of('GET', 'https://api.example/posts', 404, 'not found'),
                \Lava\HttpClient\Problem\UnexpectedStatus::of('GET', 'https://api.example/posts', 500, 'boom'),
            ];
        }

        if (class_exists(\Lava\Validate\Problem\InvalidRule::class)) {
            $email = (new \ReflectionClass(\Lava\Validate\Validation\Rules\EmailRule::class))->newInstanceWithoutConstructor();
            $int = (new \ReflectionClass(\Lava\Validate\Validation\Rules\IntRule::class))->newInstanceWithoutConstructor();
            $fixtures[\Lava\Validate\Problem\InvalidRule::class] = [
                \Lava\Validate\Problem\InvalidRule::unusablePattern('/[/', 'missing terminating ] for character class'),
                \Lava\Validate\Problem\InvalidRule::undelimited('[a-z]+'),
                \Lava\Validate\Problem\InvalidRule::emptyAllowedSet(),
                \Lava\Validate\Problem\InvalidRule::negativeLength('min', -1),
                \Lava\Validate\Problem\InvalidRule::untypedBound('max'),
                \Lava\Validate\Problem\InvalidRule::fractionalLength('max', 2.5),
                \Lava\Validate\Problem\InvalidRule::boundOnBoolean('min'),
                \Lava\Validate\Problem\InvalidRule::incompatibleFormat($email, $int),
            ];
            $fixtures[\Lava\Validate\Problem\UnreadableField::class] = [
                \Lava\Validate\Problem\UnreadableField::absent('title', 'string'),
                \Lava\Validate\Problem\UnreadableField::notCoercible('id', 'int', 'abc'),
            ];
            $fixtures[\Lava\Validate\Problem\ValidationFailed::class] = [
                \Lava\Validate\Problem\ValidationFailed::of(
                    'title',
                    new \Lava\Validate\Validation\RuleFailure($email, new \Lava\Validate\Validation\RuleViolation("'title' must be an email address.", "Send 'title' as an email address.")),
                    'nope',
                    true,
                ),
            ];
        }

        if (class_exists(\Lava\View\Problem\BadViewCall::class)) {
            $fixtures[\Lava\View\Problem\AutoescapeDisabled::class] = [
                \Lava\View\Problem\AutoescapeDisabled::of('posts/show.twig', 'off'),
            ];
            $fixtures[\Lava\View\Problem\BadViewCall::class] = [
                \Lava\View\Problem\BadViewCall::routeName(0),
                \Lava\View\Problem\BadViewCall::urlParams('posts.show', 'slug'),
                \Lava\View\Problem\BadViewCall::urlParam('posts.show', 'slug', []),
                \Lava\View\Problem\BadViewCall::featureName(0),
            ];
            $fixtures[\Lava\View\Problem\TemplateFailed::class] = [
                \Lava\View\Problem\TemplateFailed::syntax('posts/show.twig', new \Twig\Error\SyntaxError('Unexpected token')),
                \Lava\View\Problem\TemplateFailed::runtime('posts/show.twig', new \Twig\Error\RuntimeError('Variable "post" does not exist')),
            ];
            $fixtures[\Lava\View\Problem\TemplateNotFound::class] = [
                \Lava\View\Problem\TemplateNotFound::of('posts/show.twig', '/app/views', ['posts/index.twig']),
                \Lava\View\Problem\TemplateNotFound::inNamespace('@admin/show.twig', 'admin', 'show.twig', ['/app/views/admin'], ['index.twig'], ['admin']),
                \Lava\View\Problem\TemplateNotFound::included('posts/show.twig', new \Twig\Error\LoaderError('Unable to find template "partials/_nav.twig"')),
            ];
            $fixtures[\Lava\View\Problem\ViewDirMissing::class] = [
                \Lava\View\Problem\ViewDirMissing::of('/app/views', 'view.dir', '/app'),
            ];
        }

        return $fixtures;
    }

    /**
     * The `lava <command> [--flags]` invocations a fix tells the reader to run.
     *
     * Only an invocation the fix presents as one: after `Run: `, the convention
     * {@see LavaProblem}'s docblock sets, or inside backticks. Prose mentions
     * the binary too — "Run lava from your app's root directory" — and reading
     * those as invocations would report `lava from` as a missing command, which
     * is the kind of false alarm that gets a gate switched off.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function lavaCommands(string $fix): array
    {
        $invocations = [];

        preg_match_all('/Run:\s*lava ([a-z][a-z0-9:_-]*)((?:\s+--[a-z][a-z0-9-]*(?:=[^\s`]*)?)*)/', $fix, $introduced, PREG_SET_ORDER);
        preg_match_all('/`lava ([a-z][a-z0-9:_-]*)((?:\s+--[a-z][a-z0-9-]*(?:=[^`]*)?)*)`/', $fix, $quoted, PREG_SET_ORDER);

        foreach ([...$introduced, ...$quoted] as $match) {
            preg_match_all('/--([a-z][a-z0-9-]*)/', $match[2] ?? '', $flags);
            $invocations[] = [$match[1], array_values($flags[1])];
        }

        return $invocations;
    }

    /**
     * Every artifact path the framework reads.
     *
     * Three sources, each the one that owns its half, so this list cannot drift
     * from them: conventions.md's table of fixed artifacts (the documented
     * contract), the snippets {@see FrameworkReference} ships, and each
     * installed pack's own declared config files. A fix naming something none
     * of them knows is a finding either way round — the fix is wrong, or the
     * contract never wrote the artifact down.
     *
     * @return list<string>
     */
    private static function artifactPaths(string $root): array
    {
        $paths = ['public/index.php', 'database/migrations'];

        foreach (explode("\n", (string) file_get_contents($root . '/docs/conventions.md')) as $line) {
            if (preg_match('/^\|\s*`([^`]+)`\s*\|/', $line, $row) !== 1) {
                continue;
            }
            $path = $row[1];
            if (preg_match('#^(app|config|tests|public|database)/#', $path) === 1) {
                $paths[] = $path;
            }
        }

        foreach (FrameworkReference::artifacts() as $artifact) {
            if (is_array($artifact) && is_string($artifact['file'] ?? null)) {
                $paths[] = $artifact['file'];
            }
        }

        foreach (self::packInfos() as $pack) {
            foreach ($pack->configFiles as $name) {
                $paths[] = "config/{$name}.php";
            }
        }

        if (class_exists(\Lava\Events\ListenerMap::class)) {
            $paths[] = \Lava\Events\ListenerMap::FILE;
        }

        return array_values(array_unique($paths));
    }

    /**
     * Every installed pack's manifest.
     *
     * @return list<\Lava\Core\Modules\PackInfo>
     */
    private static function packInfos(): array
    {
        $packs = [];

        foreach (\Lava\Core\Map\ApiIndex::surfaces() as $surface) {
            $prefix = rtrim($surface->namespacePrefix(), '\\');
            $pack = substr($prefix, (int) strrpos($prefix, '\\') + 1);
            $module = $prefix . '\\' . $pack . 'Module';
            if (!class_exists($module)) {
                continue;
            }
            $instance = new $module();
            if ($instance instanceof \Lava\Core\Modules\Module) {
                $packs[] = $instance->pack();
            }
        }

        return $packs;
    }

    /** @param list<string> $known */
    private static function isKnownArtifact(string $path, array $known): bool
    {
        foreach ($known as $artifact) {
            if ($path === $artifact || str_starts_with($path, rtrim($artifact, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The smallest app that boots green, written fresh in a temp directory.
     *
     * Written rather than copied from a fixture: a fixture app's handlers
     * autoload through the suite's own loader, which a command run against a
     * copy of the directory does not have — the copy would fail for a reason
     * that has nothing to do with the fix being tested. This has no handlers to
     * autoload, and a fix that writes a file (`lava map`) writes it here.
     */
    private static function greenApp(): string
    {
        $app = sys_get_temp_dir() . '/lava-fix-text-' . bin2hex(random_bytes(6));
        mkdir($app . '/app', 0o777, true);
        mkdir($app . '/config', 0o777, true);
        file_put_contents(
            $app . '/app/Routes.php',
            "<?php\n\nreturn function (\\Lava\\Core\\Routing\\Router \$r): void {\n};\n",
        );

        return $app;
    }

    /**
     * Core's commands plus every installed pack's.
     *
     * A pack's fix may say `lava db:rollback`, which core's registry alone does
     * not know — checking against core only would call a real command missing.
     * The module class is derived from the pack's namespace, the shape
     * {@see ModuleRef} enforces, so this list cannot fall behind the packs.
     */
    private static function registry(): CommandRegistry
    {
        $registry = CommandRegistry::core();

        foreach (\Lava\Core\Map\ApiIndex::surfaces() as $surface) {
            $prefix = rtrim($surface->namespacePrefix(), '\\');
            $pack = substr($prefix, (int) strrpos($prefix, '\\') + 1);
            $module = $prefix . '\\' . $pack . 'Module';
            if (!class_exists($module)) {
                continue;
            }
            $instance = new $module();
            if ($instance instanceof \Lava\Core\Modules\ProvidesCommands) {
                $instance->commands($registry);
            }
        }

        return $registry;
    }

    /**
     * Short name => fully qualified, for every type `lava api` indexes.
     *
     * @return array<string, string>
     */
    private static function indexedShortNames(): array
    {
        $root = self::root() ?? dirname(__DIR__, 4);
        $names = [];

        foreach (\Lava\Core\Map\ApiIndex::of(\Lava\Core\Map\ApiIndex::surfaces(), $root)->symbols as $symbol) {
            $name = $symbol['name'];
            if (!is_string($name)) {
                continue;
            }
            $short = substr($name, (int) strrpos($name, '\\') + 1);
            $names[$short] ??= $name;
        }

        return $names;
    }

    /**
     * Every problem class the packages declare.
     *
     * @return list<class-string<LavaProblem>>
     */
    private static function problemClasses(string $root): array
    {
        $classes = [];
        foreach (glob($root . '/packages/*/src/Problem/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                continue;
            }
            if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $name) !== 1) {
                continue;
            }
            $class = trim($namespace[1]) . '\\' . $name[1];
            if (!class_exists($class) || !is_subclass_of($class, LavaProblem::class)) {
                continue;
            }
            $classes[] = $class;
        }

        return $classes;
    }

    private static function skipUnlessThePackagesAreHere(): string
    {
        $root = self::root();

        if ($root === null || glob($root . '/packages/*/src/Problem/*.php') === []) {
            self::markTestSkipped('no packages above ' . __DIR__);
        }

        return $root;
    }

    private static function root(): ?string
    {
        $directory = __DIR__;

        while ($directory !== dirname($directory)) {
            if (is_file($directory . '/docs/problem-codes.md')) {
                return $directory;
            }
            $directory = dirname($directory);
        }

        return null;
    }
}
