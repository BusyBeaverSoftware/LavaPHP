<?php

declare(strict_types=1);

namespace App\Auth;

use App\Problem\MissingSessionSecret;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The signed session cookie: mint, sign, read, clear. No database, no policy —
 * everything about *who* is signed in lives in {@see Auth}, and everything about
 * *how the bytes are protected* lives here.
 *
 * The payload is signed (HMAC-SHA256), not encrypted. Anything in it is readable
 * by whoever holds the cookie — so it holds an id, a counter, a nonce and a
 * timestamp, and nothing else. Marking the cookie `HttpOnly` keeps scripts from
 * reading it, which is what makes the nonce a usable CSRF token: a cross-site
 * attacker can *send* the cookie but cannot *learn* what is in it.
 *
 * The cookie is read from `getCookieParams()` and nowhere else. In production
 * `RequestFactory::fromGlobals()` fills that from `$_COOKIE`; under test,
 * `TestClient` fills it from its cookie jar. One reader, and it is the same one
 * in both places — so a test that signs in exercises the code a browser does.
 */
final readonly class SessionCookie
{
    public const HEADER = 'Set-Cookie';

    public function __construct(
        private string $secret,
        private string $name,
        private int $ttl,
    ) {
        // A cookie signed with an empty key is a cookie anyone can forge. The
        // `EnvVar::required` declaration in app/Services.php lists the variable
        // in `lava env` and fails `lava check --strict` without it, but a
        // production boot does not consult declarations — so the refusal also
        // lives here, where the value would be misused. The container builds
        // this at boot, so an app without a secret does not boot at all.
        if ($secret === '') {
            throw MissingSessionSecret::of();
        }
    }

    /**
     * The session this request carries, or null when there is none to trust —
     * expired ones included, since a cookie knows its own lifetime and there is
     * nothing downstream that could use an expired session for anything.
     */
    public function read(ServerRequestInterface $request): ?SessionData
    {
        $raw = $this->cookieValue($request);

        if ($raw === null) {
            return null;
        }

        $parts = explode('.', $raw);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;
        $expected = $this->sign($payload);

        // `hash_equals` rather than `===`: a byte-by-byte comparison leaks how
        // much of a guessed signature was right.
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            return null;
        }

        $session = SessionData::fromJson($json);

        if ($session === null || $session->expiredAt(time(), $this->ttl)) {
            return null;
        }

        return $session;
    }

    /** A fresh anonymous session, with a fresh nonce. */
    public function anonymous(): SessionData
    {
        return SessionData::anonymous($this->nonce(), time());
    }

    /**
     * A session for a user who has just proved who they are.
     *
     * The nonce is new, and that is the point: the cookie the browser used to
     * hold was minted before authentication and an attacker may have planted it
     * (session fixation). Rotating on privilege change is what makes a planted
     * cookie worthless.
     */
    public function authenticated(int $userId, int $epoch): SessionData
    {
        return SessionData::forUser($userId, $epoch, $this->nonce(), time());
    }

    /** The `Set-Cookie` header value that installs this session. */
    public function header(SessionData $session): string
    {
        return $this->name . '=' . $this->encode($session) . '; ' . $this->attributes($this->ttl);
    }

    /** The `Set-Cookie` header value that removes it. */
    public function clearHeader(): string
    {
        return $this->name . '=; ' . $this->attributes(0);
    }

    /**
     * `HttpOnly` is what makes the nonce secret; `SameSite=Lax` is the layer
     * that stops a cross-site POST from carrying the cookie at all. Neither is
     * a substitute for the CSRF token — `Lax` still sends the cookie on a
     * top-level GET, which is enough for some attacks — but both are free.
     */
    private function attributes(int $maxAge): string
    {
        return 'Path=/; HttpOnly; SameSite=Lax; Max-Age=' . $maxAge;
    }

    private function cookieValue(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[$this->name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function encode(SessionData $session): string
    {
        $json = json_encode($session->json(), JSON_THROW_ON_ERROR);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $payload . '.' . $this->sign($payload);
    }

    private function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->secret, true)), '+/', '-_'), '=');
    }

    private function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
