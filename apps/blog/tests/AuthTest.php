<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\SessionCookie;
use App\Tests\Support\BootedTestCase;

/**
 * Signing up, signing in, staying signed in, and the ways each of those is
 * supposed to fail.
 *
 * Auth is hand-written here, because the framework deliberately ships none —
 * which means every property below is this app's responsibility rather than a
 * library's, and a test that does not exist is a property that does not hold.
 * The three worth naming are the session-fixation defence (the CSRF nonce
 * rotates on login), the non-disclosure of which accounts exist (identical status
 * and identical message for a wrong password and an unknown address), and
 * revocation (the session epoch, which a password change bumps).
 */
final class AuthTest extends BootedTestCase
{
    public function testSignUpCreatesAnAccountAndSignsItIn(): void
    {
        $browser = $this->browser();
        $response = $browser->register('randy@example.com', 'Randy', self::PASSWORD);

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->header('Location'));
        self::assertNotNull($browser->sessionCookie());

        // Signed in for real: the header the layout renders for a known user is
        // there, and the one for a stranger is not.
        $home = $browser->get('/')->body();
        self::assertStringContainsString('Randy', $home);
        self::assertStringContainsString('Sign out', $home);
        self::assertStringNotContainsString('Sign in', $home);
    }

    public function testTheNonceRotatesOnLoginSoASessionCannotBeFixated(): void
    {
        // Session fixation: an attacker who can plant a session cookie before a
        // victim signs in would, without rotation, hold a cookie that is now
        // authenticated. The defence is that the CSRF nonce — which is the whole
        // of the session's identity in this design — is replaced on login.
        //
        // The test reads the nonce out of the token the pages render, because
        // that is the observable consequence: the token a page hands out before
        // signing in stops being accepted afterwards.
        $browser = $this->browser();

        $before = $browser->token('/login');
        self::assertNotSame('', $before);

        $signedIn = $browser->register('randy@example.com', 'Randy', self::PASSWORD);
        self::assertSame(303, $signedIn->status());

        $after = $browser->token('/posts/new');

        self::assertNotSame($before, $after, 'The session nonce must not survive a login.');

        // And the stale one is genuinely refused, not merely different.
        $response = $browser->post('/posts', [
            'csrf' => $before,
            'title' => 'Written with a stolen token',
            'body' => 'nope',
        ]);

        self::assertSame(403, $response->status());
        self::assertSame(0, $this->rowCount('posts'), 'A refused CSRF token must not create a post.');
    }

    public function testAStaleTokenFromBeforeSignInIsRefused(): void
    {
        $browser = $this->browser();
        $token = $browser->token('/login');

        $browser->register('randy@example.com', 'Randy', self::PASSWORD);

        $response = $browser->post('/posts', ['csrf' => $token, 'title' => 'nope', 'body' => 'nope']);

        self::assertSame(403, $response->status());
        self::assertSame(0, $this->rowCount('posts'));
    }

    public function testSignInWorksWithTheRightPassword(): void
    {
        $this->user('randy@example.com', 'Randy');

        $browser = $this->browser();
        $response = $browser->signIn('randy@example.com', self::PASSWORD);

        self::assertSame(303, $response->status());
        self::assertStringContainsString('Randy', $browser->get('/')->body());
    }

    public function testAWrongPasswordAndAnUnknownAccountAreIndistinguishable(): void
    {
        // Non-disclosure, and the reason it is a test rather than a comment: two
        // responses that differ in status or in wording turn the login form into
        // an oracle for which addresses have accounts.
        //
        // The timing side of the same leak is closed in `PasswordHasher::verify()`
        // — it compares against a decoy hash when the account is unknown, so the
        // work done is the same either way. Timing is not asserted here because a
        // wall-clock assertion in CI is flaky; the structural claim is.
        $this->user('randy@example.com', 'Randy');

        $wrongPassword = $this->browser()->signIn('randy@example.com', 'not-the-password');
        $unknownAccount = $this->browser()->signIn('nobody@example.com', 'not-the-password');

        self::assertSame(401, $wrongPassword->status());
        self::assertSame(401, $unknownAccount->status());

        self::assertSame(
            $this->errorOn($wrongPassword->body()),
            $this->errorOn($unknownAccount->body()),
            'The two failures must not be told apart by their message.',
        );
    }

    public function testSigningInAndBackOutAgain(): void
    {
        $this->user('randy@example.com', 'Randy');
        $browser = $this->browser();
        $browser->signIn('randy@example.com', self::PASSWORD);

        $response = $browser->post('/logout', ['csrf' => $browser->token('/')]);

        self::assertSame(303, $response->status());

        // The header is what decides this, not the cookie still being present:
        // the anonymous cookie the logout response set is a valid session for
        // nobody, which is the point. The assertion is on what the pages show.
        $home = $browser->get('/')->body();
        self::assertStringContainsString('Sign in', $home);
        self::assertStringNotContainsString('Sign out', $home);
    }

    public function testSignUpIsRefusedWhenTheAddressIsAlreadyRegistered(): void
    {
        $this->user('randy@example.com', 'Randy');
        $browser = $this->browser();

        $response = $browser->register('randy@example.com', 'Imposter', self::PASSWORD);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('already registered', $response->body());

        // Still one account: the failure did not half-create a second one.
        self::assertSame(1, $this->rowCount('users'));
    }

    public function testPasswordsThatDoNotMatchAreRefusedWithAUsableMessage(): void
    {
        $browser = $this->browser();
        $response = $browser->post('/register', [
            'csrf' => $browser->token('/register'),
            'email' => 'randy@example.com',
            'display_name' => 'Randy',
            'password' => 'a-long-enough-password',
            'password_confirm' => 'a-different-password',
        ]);

        self::assertSame(422, $response->status());

        // The message has to be *for the person who mistyped the field*. This
        // assertion exists because it once was not: an earlier version's message
        // explained validator internals, which is a note for whoever maintains
        // the app and reads as nonsense to somebody who fumbled their
        // confirmation field.
        self::assertStringContainsString('The two passwords do not match.', $response->body());
        self::assertStringNotContainsString('lavaphp/validate', $response->body());

        self::assertSame(0, $this->rowCount('users'));
    }

    public function testANewAccountIsNotCreatedWhenTheFormIsInvalid(): void
    {
        $browser = $this->browser();
        $response = $browser->post('/register', [
            'csrf' => $browser->token('/register'),
            'email' => 'not-an-email',
            'display_name' => 'Randy',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
        ]);

        self::assertSame(422, $response->status());
        self::assertSame(0, $this->rowCount('users'));
    }

    public function testTheCookieIsNotReadableByScript(): void
    {
        // `HttpOnly` is the attribute that keeps an XSS in this app from being an
        // account takeover. It is one string in `SessionCookie::header()` and
        // nothing else in the app would notice its absence, so it is asserted.
        $response = $this->browser()->get('/');
        $header = $response->header('Set-Cookie');

        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Path=/', $header);
        // No `Secure`: the app is served over plain HTTP in development, and a
        // `Secure` cookie would simply never be sent there. Named here so the
        // omission reads as a decision rather than an oversight — it must be set
        // for any deployment behind TLS.
        self::assertStringNotContainsString('Secure', $header);
    }

    public function testATamperedCookieIsTreatedAsAStrangerRatherThanAnError(): void
    {
        // The session cookie is signed, so a forged one fails verification and
        // the holder is a stranger. The interesting part is the shape of the
        // answer: no error page, no hint that the cookie was looked at — just an
        // anonymous session, because there is nothing useful to tell somebody who
        // presented a cookie this app did not issue.
        $forged = (new SessionCookie(self::SECRET, 'lava_blog_session', 1209600))
            ->header(new \App\Auth\SessionData(1, 0, 'forged-nonce', time()));

        $response = $this->browser()->get('/', [
            'Cookie' => $this->tampered($forged),
        ]);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Sign in', $response->body());
        self::assertStringNotContainsString('Sign out', $response->body());
    }

    /**
     * Flip a character in the signature, leaving the payload untouched — the
     * shape of forgery the HMAC exists to catch, rather than a random string
     * that would fail to parse for a different reason.
     */
    private function tampered(string $setCookie): string
    {
        $pair = explode(';', $setCookie, 2)[0];
        $value = explode('=', $pair, 2)[1];

        $last = substr($value, -1);

        return substr($value, 0, -1) . ($last === 'A' ? 'B' : 'A');
    }

    /** The visible error text on a re-rendered form. */
    private function errorOn(string $html): string
    {
        if (preg_match('/class="error">([^<]*)</', $html, $matches) !== 1) {
            self::fail('Expected one form error.');
        }

        return trim($matches[1]);
    }

}
