<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Lava\Core\Testing\TestClient;
use Lava\Core\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * A visitor with a browser: a browser's `Accept`, and the two things a person
 * does with this app's forms — read the CSRF token off the page, and sign up or
 * sign in.
 *
 * The cookies are `TestClient`'s own. It keeps a jar the way a browser does, so
 * a visitor who signs in stays signed in, and each `new TestClient($app)` is a
 * different visitor. What this class adds is the vocabulary of this app's tests,
 * not transport.
 *
 * `token()` is the reason it exists: a CSRF token lives in the form, not in the
 * cookie, so a test that wants to POST has to fetch the page that hosts the form
 * and read the hidden input — which is exactly what a person does, and is why a
 * token that stops being rendered breaks the suite loudly.
 */
final class Browser
{
    /** The session cookie's name — `app.session_cookie`'s default in app/Services.php. */
    public const SESSION_COOKIE = 'lava_blog_session';

    public function __construct(private readonly TestClient $client)
    {
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): TestResponse
    {
        return $this->client->get($path, [...self::browserHeaders(), ...$headers]);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $headers
     */
    public function post(string $path, array $form = [], array $headers = []): TestResponse
    {
        return $this->client->form('POST', $path, $form, [...self::browserHeaders(), ...$headers]);
    }

    /**
     * A browser's `Accept`, sent on every request this class makes.
     *
     * Not optional, because a `Browser` that did not send it would not be one:
     * `HttpErrors` and `ErrorPageMiddleware` both decide the media from this
     * header, and a request naming neither media is an *agent* by that rule. A
     * test that wants the agent's answer overrides `Accept` at the call site,
     * where the override is visible.
     *
     * @return array<string, string>
     */
    private static function browserHeaders(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (Test) LavaBlogTest/1.0',
        ];
    }

    /**
     * The CSRF token on the form at `$path`, as a browser would read it.
     *
     * Also the way a first-time visitor gets a session before a POST, since the
     * token and the session that issued it have to be carried together.
     */
    public function token(string $path): string
    {
        $body = $this->get($path)->body();

        if (preg_match('/name="csrf" value="([^"]*)"/', $body, $matches) !== 1) {
            Assert::fail("No CSRF token rendered on {$path} — is the form still there?");
        }

        return $matches[1];
    }

    /** The session cookie this visitor holds, or null before the first response. */
    public function sessionCookie(): ?string
    {
        return $this->client->cookies()->get(self::SESSION_COOKIE);
    }

    /**
     * Register and stay signed in — the two-step every authenticated test needs.
     *
     * Returns the response to the POST so a caller can assert on it, though most
     * callers care only about the side effect.
     *
     * @param array<string, string> $headers
     */
    public function register(
        string $email,
        string $displayName,
        string $password,
        array $headers = [],
    ): TestResponse {
        return $this->post('/register', [
            'csrf' => $this->token('/register'),
            'email' => $email,
            'display_name' => $displayName,
            'password' => $password,
            'password_confirm' => $password,
        ], $headers);
    }

    /**
     * Sign in through the real form, rotating the nonce exactly as a login does.
     *
     * @param array<string, string> $headers
     */
    public function signIn(string $email, string $password, string $next = '', array $headers = []): TestResponse
    {
        $path = $next === '' ? '/login' : '/login?next=' . rawurlencode($next);

        return $this->post('/login', [
            'csrf' => $this->token($path),
            'next' => $next,
            'email' => $email,
            'password' => $password,
        ], $headers);
    }
}
