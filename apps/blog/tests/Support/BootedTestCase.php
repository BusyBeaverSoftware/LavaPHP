<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\PasswordHasher;
use App\Auth\User;
use App\Auth\UserRepository;
use Lava\Core\Boot\App;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Lava\Db\Connection;
use Lava\Db\Migration\MigrationFiles;
use Lava\Db\Migration\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Boots the app once per class, against a database that lives only as long as
 * the process.
 *
 * `sqlite::memory:` rather than the dev `var/blog.sqlite`, and the difference is
 * not tidiness: a suite that shares the development database writes test users
 * into whatever the developer was looking at, and passes or fails depending on
 * what was already in it. An in-memory database is empty by construction, so a
 * uniqueness test is testing the constraint and not the leftovers.
 *
 * `SESSION_SECRET` is supplied here because `SessionCookie`'s constructor
 * rejects an empty one and the container builds it at boot — without it the app
 * does not boot, which is the right behaviour and a useless test failure. The
 * value is a literal: it signs cookies inside one process and is never a secret.
 */
abstract class BootedTestCase extends TestCase
{
    protected const PASSWORD = 'a-long-enough-password';
    protected const SECRET = 'test-only-session-secret-not-a-real-one';

    protected static App $app;
    protected static Connection $db;

    public static function setUpBeforeClass(): void
    {
        $app = TestApp::boot(dirname(__DIR__, 2), [
            'DATABASE_DSN' => 'sqlite::memory:',
            'SESSION_SECRET' => self::SECRET,
        ]);
        self::assertInstanceOf(App::class, $app, 'The app must boot for any of this to mean anything.');

        $connection = $app->container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        (new MigrationRunner($connection, MigrationFiles::inApp($app->appDir)))->migrate();

        self::$app = $app;
        self::$db = $connection;
    }

    /**
     * Posts are deleted before users, and not because of any ordering
     * preference: `posts.author_id` has an `ON DELETE CASCADE` foreign key, so
     * removing the users first would take the posts with them and leave the
     * `posts` delete with nothing to do — the tests would still pass, and the
     * next person to add a case would be reading a fixture rule that does not
     * exist. Explicit order, so the cascade stays a schema fact and not a
     * hidden dependency of the suite.
     */
    protected function setUp(): void
    {
        self::$db->run(self::$db->table('posts')->whereRaw('1 = 1')->delete());
        self::$db->run(self::$db->table('users')->whereRaw('1 = 1')->delete());
    }

    /**
     * A new visitor: a client of its own, so its own cookies. Two browsers in
     * one test are two people, which is what the authorization tests need.
     */
    protected function browser(): Browser
    {
        return new Browser(new TestClient(self::$app));
    }

    /**
     * A registered user, for tests that are about posts rather than sign-up.
     *
     * The password is hashed rather than passed through: `create()` takes a
     * *hash*, and handing it `self::PASSWORD` would store the plaintext in the
     * `password_hash` column. That would still let a test insert a user — and
     * would quietly make every "sign in as this user" test fail, or worse, teach
     * the next reader that the repository takes plaintext.
     */
    protected function user(string $email = 'randy@example.com', string $name = 'Randy'): User
    {
        $users = self::$app->container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $hasher = self::$app->container->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);

        $user = $users->create($email, $name, $hasher->hash(self::PASSWORD));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** A signed-in browser, for tests that are not about signing in. */
    protected function signedIn(string $email = 'randy@example.com', string $name = 'Randy'): Browser
    {
        $browser = $this->browser();
        $response = $browser->register($email, $name, self::PASSWORD);

        self::assertSame(303, $response->status(), 'Registration should sign the new user in.');
        self::assertNotNull($browser->sessionCookie(), 'Registration should have set a session cookie.');

        return $browser;
    }

    /**
     * Writes a post through the form and returns its id, read off the redirect.
     *
     * The id is *read*, never assumed. Rows are deleted between tests but SQLite
     * does not rewind the rowid sequence, so the first post of the second test is
     * id 2 — and a test asserting `/posts/1` would pass alone and fail in
     * company, which is the worst way for a suite to be wrong. Taking the id from
     * the `Location` the app itself produced also means the assertion tests the
     * redirect rather than working around it.
     */
    protected function createPost(
        Browser $browser,
        string $title = 'A post',
        string $body = 'A body',
        bool $published = true,
    ): int {
        $form = [
            'csrf' => $browser->token('/posts/new'),
            'title' => $title,
            'body' => $body,
        ];

        // An unchecked checkbox is simply absent from a form body — that is what
        // a browser sends — so the draft case omits the field rather than
        // sending it as "0".
        if ($published) {
            $form['published'] = '1';
        }

        $response = $browser->post('/posts', $form);

        self::assertSame(303, $response->status(), 'Saving a post should redirect.');

        $location = $response->header('Location');
        self::assertMatchesRegularExpression('#^/posts/\d+$#', $location, 'The redirect should name the new post.');

        return (int) substr($location, strlen('/posts/'));
    }

    /**
     * A row count, through the raw SQL hatch.
     *
     * The query builder has no aggregate: `select()` quotes every column through
     * `Dialect::quote()`, which splits on `.` and wraps each segment, so
     * `COUNT(*)` and an `AS` alias are both unrepresentable. `Connection::query()`
     * is the documented way out, and a count is exactly the case it is for.
     *
     * Not named `count()`: `PHPUnit\Framework\TestCase::count()` is final, and a
     * child redefining it is a fatal error at load time rather than a failure at
     * run time.
     *
     * `$table` is a literal at every call site; the interpolation is a
     * convenience for a test, not a pattern to copy.
     */
    protected function rowCount(string $table): int
    {
        $rows = self::$db->query("SELECT COUNT(*) AS total FROM {$table}");
        $total = $rows[0]['total'] ?? 0;

        return is_numeric($total) ? (int) $total : 0;
    }
}
