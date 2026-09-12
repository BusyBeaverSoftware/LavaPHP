<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

use Psr\Http\Message\ResponseInterface;

/**
 * The cookies a {@see TestClient} holds between requests — what a browser holds
 * for one site.
 *
 * A test client without one sends every request as a first-time visitor: sign
 * in, and the next request is a stranger again. The workaround every
 * session-based app then writes is a wrapper that copies `Set-Cookie` back into
 * a `Cookie` header, and each one gets the edges slightly differently. This is
 * that wrapper, once, with the edges that change what a test observes:
 *
 *  - **a cookie is removed** by `Max-Age=0` or less, or by an `Expires` in the
 *    past — which is how every app signs a user out;
 *  - **`Path` scopes it** (RFC 6265 §5.1.4): `Path=/admin` reaches `/admin` and
 *    `/admin/users`, never `/administrator`, and a cookie set with no `Path`
 *    is scoped to the directory of the request that set it;
 *  - **the narrower path wins** when one name is set under two paths, which is
 *    the order a browser sends them in (§5.4).
 *
 * And the attributes that do not, deliberately ignored: `Domain` (a test client
 * talks to one app), `HttpOnly` and `SameSite` (there is no script and no other
 * site), and `Secure` — a test has no TLS, and a session cookie marked `Secure`
 * for production must not silently vanish from the suite that tests it.
 *
 * Values are stored exactly as the response wrote them. {@see TestClient}
 * decodes them for `getCookieParams()`, the way PHP decodes `$_COOKIE`.
 */
final class CookieJar
{
    /**
     * Keyed by name and path together, because that pair is a cookie's identity:
     * the same name set under `/` and under `/admin` is two cookies, and a
     * request to `/admin` carries both.
     *
     * @var array<string, array{name: string, value: string, path: string, expires: int|null}>
     */
    private array $cookies = [];

    /**
     * The value a request to `$path` would carry under `$name`, or null when it
     * would carry none.
     */
    public function get(string $name, string $path = '/'): ?string
    {
        return $this->forPath($path)[$name] ?? null;
    }

    /**
     * Every cookie a request to `$path` would carry, as name => value.
     *
     * @return array<string, string>
     */
    public function forPath(string $path): array
    {
        $now = time();

        // Grouped by path length rather than sorted with a comparator: the
        // longest path must win a shared name, and within one length the
        // cookie set first comes first — which a group keeps for free.
        $byLength = [];
        foreach ($this->cookies as $cookie) {
            if ($cookie['expires'] !== null && $cookie['expires'] <= $now) {
                continue;
            }
            if (self::pathMatches($cookie['path'], $path)) {
                $byLength[strlen($cookie['path'])][] = $cookie;
            }
        }
        krsort($byLength);

        $values = [];
        foreach ($byLength as $group) {
            foreach ($group as $cookie) {
                $values[$cookie['name']] ??= $cookie['value'];
            }
        }

        return $values;
    }

    /**
     * Hold a cookie as though a response had set it — for a test that starts
     * mid-session rather than signing in first.
     */
    public function set(string $name, string $value, string $path = '/'): void
    {
        $this->cookies[self::key($name, $path)] = [
            'name' => $name,
            'value' => $value,
            'path' => $path,
            'expires' => null,
        ];
    }

    /** Forget every cookie: the same client, as a first-time visitor. */
    public function clear(): void
    {
        $this->cookies = [];
    }

    /**
     * Take in every `Set-Cookie` a response carried.
     *
     * @param string $requestPath the path the response answered — where a cookie
     *        with no `Path` attribute is scoped
     */
    public function absorb(ResponseInterface $response, string $requestPath): void
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            $this->absorbLine($line, $requestPath);
        }
    }

    private function absorbLine(string $line, string $requestPath): void
    {
        $parts = explode(';', $line);
        $pair = $parts[0];
        $equals = strpos($pair, '=');

        // RFC 6265 §5.2: a set-cookie-string with no `=` in its first part, or
        // with an empty name, is ignored — not stored under a made-up name.
        if ($equals === false) {
            return;
        }
        $name = trim(substr($pair, 0, $equals));
        if ($name === '') {
            return;
        }
        $value = trim(substr($pair, $equals + 1));

        $path = null;
        $maxAge = null;
        $expires = null;
        foreach (array_slice($parts, 1) as $attribute) {
            $at = strpos($attribute, '=');
            $key = strtolower(trim($at === false ? $attribute : substr($attribute, 0, $at)));
            $argument = $at === false ? '' : trim(substr($attribute, $at + 1));

            if ($key === 'path' && str_starts_with($argument, '/')) {
                $path = $argument;
            } elseif ($key === 'max-age') {
                // Validated and converted in one step; a value that is not a
                // whole number leaves the attribute unset, as §5.2.2 says.
                $seconds = filter_var($argument, FILTER_VALIDATE_INT);
                $maxAge = $seconds === false ? $maxAge : $seconds;
            } elseif ($key === 'expires') {
                $time = strtotime($argument);
                $expires = $time === false ? $expires : $time;
            }
        }

        $path ??= self::defaultPath($requestPath);
        // Max-Age wins over Expires when a response sends both (§5.3 step 3).
        $expiry = $maxAge !== null ? time() + $maxAge : $expires;
        $key = self::key($name, $path);

        if ($expiry !== null && $expiry <= time()) {
            unset($this->cookies[$key]);
            return;
        }

        $this->cookies[$key] = ['name' => $name, 'value' => $value, 'path' => $path, 'expires' => $expiry];
    }

    /** §5.1.4: the request path is the cookie path, or sits beneath it at a `/`. */
    private static function pathMatches(string $cookiePath, string $requestPath): bool
    {
        if ($requestPath === $cookiePath) {
            return true;
        }
        if (!str_starts_with($requestPath, $cookiePath)) {
            return false;
        }

        return str_ends_with($cookiePath, '/') || substr($requestPath, strlen($cookiePath), 1) === '/';
    }

    /** §5.1.4: everything up to, not including, the request path's last `/`. */
    private static function defaultPath(string $requestPath): string
    {
        $last = strrpos($requestPath, '/');

        return $last === false || $last === 0 ? '/' : substr($requestPath, 0, $last);
    }

    private static function key(string $name, string $path): string
    {
        return $path . "\n" . $name;
    }
}
