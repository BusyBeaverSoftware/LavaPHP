# Sessions, sign-in and CSRF

LavaPHP ships no session, sign-in or CSRF support, so an app with a sign-in
writes its own. This page covers the design [`apps/blog`](../apps/blog/) uses,
the mistakes a real build made, and a checklist. `apps/blog` is not on
Packagist, so installing from there never shows it to you. The D1–D10 entries
cited below are in its [`DECISIONS.md`](../apps/blog/DECISIONS.md).

## Why there is no session pack yet

v1 leaves this out on purpose: shipping no opinion beats shipping a wrong
security-sensitive default. The planned `lavaphp/session` pack is the next
milestone, and it will plug into the same module contract every pack uses
([DECISIONS.md](../DECISIONS.md) 266). Until it lands, you get the seams and nothing built on them. They are global
middleware ([the handler contract](conventions.md#the-handler-contract)),
request attributes, and a `TestClient` that keeps cookies. A cookie is a plain
`Set-Cookie` header: `Lava\Core\Http\Responses` has five named constructors and
no cookie helper. Hand-written auth is an app's highest-consequence code, and
nothing upstream will fix it for you (D1).

## The design `apps/blog` uses

### A signed session cookie

[`SessionCookie`](../apps/blog/app/Auth/SessionCookie.php) writes the session
as base64url JSON, a dot, and an HMAC-SHA256 signature.
[`SessionData`](../apps/blog/app/Auth/SessionData.php) holds four fields: the
user id (null before sign-in), the epoch, the CSRF nonce and the issue time.

It is **signed, not encrypted**. Whoever holds the cookie can read it, so it
carries only those fields, and `HttpOnly` keeps scripts from reading the nonce.
The signature is checked with `hash_equals`. `SessionData::expiredAt()` refuses
anything older than `app.session_ttl`, whatever `Max-Age` said, because a
client can replay an old cookie. A tampered or expired cookie is not an error:
it reads as null, and the visitor gets a fresh anonymous session.
[`app/Services.php`](../apps/blog/app/Services.php) reads `SESSION_SECRET` with
`ProcessEnv::real()` and declares it with `EnvVar::required(…, secret: true)`,
so `lava env` lists it with the value redacted. An empty secret makes the
constructor throw `MissingSessionSecret`.

[`SessionMiddleware`](../apps/blog/app/Http/SessionMiddleware.php) is global and
gives every request a session, anonymous ones included: the login form needs a
token before anyone signs in. It attaches the session as the `session`
attribute (`SessionMiddleware::ATTRIBUTE`). It sets the cookie **only when the
request arrived without a valid one**. The sign-in and sign-out handlers set
their own `Set-Cookie`, and rewriting the cookie every time would overwrite
theirs. [`Auth`](../apps/blog/app/Auth/Auth.php) is a singleton, so every
method takes the request: `user($request)`, `csrf($request)`,
`assertCsrf($request, $form)`. A missing attribute throws `SessionMissing`
rather than rendering a token no browser holds.

### The CSRF token is the session nonce

There is no second token (D2). Forms send the nonce as a hidden `csrf` field,
and JSON clients send it as `X-CSRF-Token`:

```php
$sent = $form['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');

if (!is_string($sent) || $sent === '') {
    throw CsrfMismatch::of(false);
}
if (!hash_equals($this->csrf($request), $sent)) {
    throw CsrfMismatch::of(true);
}
```

Because the token is part of the session, it matches that session only. A
mismatch is `csrf_mismatch`, 403. The cost: one token for the session's life,
not one per form.

The check is called in each handler, not run as middleware. The callers are
`AuthController::register()`, `login()` and `logout()`, `BlogController`'s write
handlers, and `PostApiController::store()`. A new state-changing handler without
the call is unprotected, so give each one a test that posts without a token.
Checking sign-in stops another site from signing a visitor in as the attacker.

### Rotation, and POST-only sign-out

After sign-up or sign-in, [`AuthController`](../apps/blog/app/Http/AuthController.php)
sets `SessionCookie::authenticated($userId, $epoch)` on the 303. That session
has a **new** nonce, so a cookie planted beforehand (session fixation) and any
token captured before sign-in stop working. Sign-out installs a fresh
`SessionCookie::anonymous()` session rather than deleting the cookie, so older
tokens die then too. `AuthTest` asserts the token changes at sign-in and the old
one gets a 403.

[`app/Routes.php`](../apps/blog/app/Routes.php) registers only
`$r->post('/logout', 'logout')`. The layout's "Sign out" is a form carrying
`csrf`, because a GET sign-out would fire from an `<img src>` on any site.

### Revocation by epoch

A signed cookie cannot be withdrawn, and there is no session table (D9). Sign-in
copies `users.session_epoch` into the cookie, and `Auth::user()` refuses a
mismatch:

```php
if ($user === null || $user->sessionEpoch !== $session->epoch) {
    return null;
}
```

`UserRepository::changePassword()` bumps the epoch, ending every session the
user has. `UserRepository::rehash()` runs at sign-in when
`PasswordHasher::needsRehash()` asks, and it does not bump the epoch, because
the password is unchanged. `BlogTest` tests both.

### Where the middleware goes

[`app/Middleware.php`](../apps/blog/app/Middleware.php), outermost first:

```php
return [
    \App\Http\SessionMiddleware::class,
    \App\Http\ErrorPageMiddleware::class,
];
```

`SessionMiddleware` is first. Every inner layer then sees the session, and its
`Set-Cookie` lands on inner responses such as a gate's redirect. The error page
sits inside it because a PSR-7 request is immutable. The attribute exists only
on the request passed down, and the error page's layout needs `csrf` (D6).
Sign-in gates are route middleware, so `app/Routes.php` shows which routes need
a user (D5):

```php
$r->post('/api/posts', 'api.posts.store')
    ->handler([PostApiController::class, 'store'])
    ->middleware(RequireLoginJsonMiddleware::class);
```

[`RequireLoginMiddleware`](../apps/blog/app/Http/RequireLoginMiddleware.php)
redirects to `/login?next=…`.
[`RequireLoginJsonMiddleware`](../apps/blog/app/Http/RequireLoginJsonMiddleware.php)
answers 401 `not_authenticated` via `HttpErrors::toResponse()`. Both are
registered in `app/Services.php`, like every middleware.

### `user` and `csrf` reach templates through the render context

[`Page::context()`](../apps/blog/app/Http/Page.php) merges both into every
render, **last**, so a caller cannot overwrite them (D7):

```php
return $view->render('login', Page::context($auth, $request, [
    'errors' => [],
    'values' => ['email' => '', 'next' => Redirects::safeNext($query['next'] ?? null)],
]));
```

Do not add them as Twig globals via `ViewRenderer::environment()`. The renderer
is a singleton, so a global set for one request is still set for the next
request that process serves, and one visitor's name reaches the next. With
`strict_variables` on, a render that forgets the context fails loudly.

### Testing sign-in with `TestClient`

`TestClient` keeps a `CookieJar`. It honours `Max-Age=0` and `Path`, and
deliberately ignores `Secure`, `HttpOnly`, `SameSite` and `Domain`. Each
`new TestClient($app)` is a separate visitor. Sign in through the real form: a
hand-built cookie proves only that the signer round-trips (D10).

```php
$client = new TestClient($app); // $app = TestApp::boot($dir, ['SESSION_SECRET' => '…'])
preg_match('/name="csrf" value="([^"]*)"/', $client->get('/login')->body(), $m);

$response = $client->form('POST', '/login', [
    'csrf' => $m[1], 'email' => 'randy@example.com', 'password' => 'a-long-enough-password',
]);

self::assertSame(303, $response->status());
self::assertNotNull($client->cookies()->get('lava_blog_session'));
```

[`tests/Support/Browser.php`](../apps/blog/tests/Support/Browser.php) wraps
this. [`AuthTest`](../apps/blog/tests/AuthTest.php) covers nonce rotation, a
refused pre-sign-in token, identical answers for a wrong password and an unknown
address, the cookie's flags, and a tampered cookie.
[`BlogTest`](../apps/blog/tests/BlogTest.php) holds the epoch tests.

## Pitfalls

Lava Notes was an outside blog built on LavaPHP 0.1.2 from Packagist, and it is
not in this repository. It wrote its own sessions, CSRF and sign-in throttling
and shipped the flaws below. The maintainer review reproduced each one against
that app.

### A CSRF token not bound to the session

Wrong: a signed double-submit cookie (condensed from Lava Notes).

```php
$token = $this->signer->verify(Cookies::read($request, 'blog_csrf'));
if ($token === null || !hash_equals($token, $sentToken)) {
    throw CsrfTokenMismatch::create(/* … */);
}
```

The signature proves the site issued the cookie, not who it was issued to. An
attacker takes a signed token from any page and plants it in a signed-in
victim's browser, from a sibling subdomain or over plain HTTP. A forged form
carrying that token then passes. In the review, a forged `POST /admin/posts`
created a post. Right: the token lives inside the signed session and rotates at
sign-in, as in `Auth::assertCsrf()` above.

### An open redirect through `next`

Wrong: checking only for `//` and a backslash (Lava Notes).

```php
return str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\')
    ? $next : null;
```

`?next=%2F%09%2Fevil.example` arrives as `/<TAB>/evil.example` and passes. URL
parsers strip tab, CR and LF, so the review's
`303 Location: /<TAB>/evil.example` resolves, under the WHATWG URL parser, to
`http://evil.example/`.

**`apps/blog` had the same gap until this page was written.**
[`Redirects::safeNext()`](../apps/blog/app/Http/Redirects.php) rejected `//`,
`/\`, CR and LF, but not tab, so `safeNext("/\t/evil.example")` came back
unchanged. It now refuses any control character or backslash anywhere, and
[`SignInHardeningTest`](../apps/blog/tests/SignInHardeningTest.php) covers the
tab.

Right, as `apps/blog` now does: refuse any control character or backslash
anywhere, or redirect only to route names. Test the tab case either way.

```php
if (!is_string($raw) || !str_starts_with($raw, '/') || str_starts_with($raw, '//')) {
    return $fallback;
}
// Parsers strip tab/CR/LF (`/<TAB>/host` becomes `//host`); `\` reads as `/`.
return preg_match('/[\x00-\x1F\x7F\\\\]/', $raw) === 1 ? $fallback : $raw;
```

### Sign-in timing reveals which emails have accounts

In `apps/blog`, a wrong password and an unknown address get the same 401 and
message. [`PasswordHasher::verify()`](../apps/blog/app/Auth/PasswordHasher.php)
also checks an unknown address against a decoy bcrypt hash. The decoy helps only
if it costs what a real hash costs.

Wrong: a decoy cheaper than real hashes (Lava Notes).

```php
$hash = $user === null
    ? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG' // cost 10
    : (string) $user['password_hash'];                                // PASSWORD_DEFAULT: cost 12 on PHP 8.4+
```

The review measured 59 ms for an unknown email and 234 ms for a real account.
The status and message were identical.

Right: build the decoy with the same parameters as real hashes, and test that
they agree. A wall-clock test would be flaky (D1). `apps/blog`'s decoy was a
fixed cost-12 hash, and `apps/blog` allows `php: ^8.3`, whose default is cost
10, which made the decoy the slow path: the same leak, reversed.
`PasswordHasher::decoyHash()` now uses that hash only when `PASSWORD_DEFAULT`
agrees, and hashes a fresh decoy when it does not. `SignInHardeningTest` holds
it to that:

```php
self::assertFalse(password_needs_rehash($hasher->decoyHash(), PASSWORD_DEFAULT));
```

### A rate limiter that checks, then inserts

`apps/blog` does not limit sign-in attempts. Add a limit, and make it atomic.

Wrong: count, then insert (Lava Notes).

```php
$recent = $db->query('SELECT COUNT(*) AS n FROM rate_limit_hits WHERE bucket = ? AND hit_at > ?', [$bucket, $windowStart]);
if ((int) ($recent[0]['n'] ?? 0) >= $limit) {
    return false;
}
$db->run($db->table('rate_limit_hits')->insert(['bucket' => $bucket, 'hit_at' => $now]));
```

Concurrent requests all count before any inserts. In the review, 40 concurrent
sign-ins against a limit of 5 let 10 through.

Right: increment in one statement, then read. N requests make N increments, and
each reads at least its own, so at most `$limit` pass.

```php
$window = intdiv(time(), $seconds) * $seconds;
$db->statement(
    'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
     ON CONFLICT (bucket, window_start) DO UPDATE SET hits = rate_limits.hits + 1',
    [$bucket, $window],
);
$hits = $db->query('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $window])[0]['hits'] ?? 0;

return (int) $hits <= $limit;
```

`Connection::statement()` and `Connection::query()` are `lavaphp/db`'s raw
escape hatches. The upsert is SQLite and PostgreSQL syntax; MySQL uses
`ON DUPLICATE KEY UPDATE`. Purge expired windows for every bucket, and key IPv6
addresses on their /64.

### Cookie flags

| Attribute | Why | In `apps/blog` |
|---|---|---|
| `HttpOnly` | Scripts cannot read the cookie or its nonce. | Set, and asserted |
| `SameSite=Lax` | A cross-site POST does not carry the cookie. A top-level GET still does, so the token stays necessary. | Set, and asserted |
| `Secure` | The cookie never travels over plain HTTP. | **Not set**: dev is plain HTTP, and `AuthTest` asserts it is absent. Set it behind TLS. |
| `__Host-` prefix | Requires `Secure`, `Path=/` and no `Domain`, so a sibling subdomain or HTTP page cannot plant it. | **Not used.** Recommended. |

Wrong in production (what `apps/blog` sends in development), then right behind TLS:

```
Set-Cookie: lava_blog_session=…; Path=/; HttpOnly; SameSite=Lax; Max-Age=1209600
Set-Cookie: __Host-session=…; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=1209600
```

Browsers refuse a `__Host-` cookie without `Secure`, so set the name and the
flag from one decision. Lava Notes set `Secure` only when `base_url` was
`https://`, which left plain HTTP open for planting its CSRF cookie. `CookieJar`
ignores `Secure`, so tests keep passing.

### Trusting `X-Forwarded-For`

Wrong: any client can send this header, so every forged value gets a fresh
limiter bucket.

```php
$ip = trim(explode(',', $request->getHeaderLine('X-Forwarded-For'))[0]);
```

Right: use the peer address, which `RequestFactory::fromGlobals()` keeps from
`$_SERVER` through nyholm's `ServerRequestCreator`.

```php
$ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
```

LavaPHP has no trusted-proxy setting. Behind a load balancer, either have the
web server rewrite `REMOTE_ADDR` for proxies you name (nginx `set_real_ip_from`,
Apache `mod_remoteip`), or honour the header only when `REMOTE_ADDR` is on a
proxy list in your own config. Otherwise every visitor shares the proxy's
address, and one person's failed sign-ins lock out everyone. `apps/blog` reads
no client address. `TestClient` sends no server params, so test the
missing-address case.

## Before you ship sign-in

- [ ] `SESSION_SECRET` is 32+ random bytes, uncommitted, and refused when empty.
- [ ] The cookie is HMAC-signed, compared with `hash_equals`, and expired on the server.
- [ ] The cookie is `HttpOnly`, `SameSite=Lax` and `Path=/`, plus `Secure` and `__Host-` behind TLS.
- [ ] Every state-changing handler checks a session-bound CSRF token, including sign-in, sign-out and JSON writes.
- [ ] Sign-in and sign-out rotate the nonce, and a test shows an old token gets a 403.
- [ ] A password change ends every session and a rehash does not, both tested. Sign-out is POST only.
- [ ] Wrong password and unknown address look identical, and a test checks the decoy's cost against `PASSWORD_DEFAULT`.
- [ ] `next` refuses control characters and backslashes; tests cover `//host`, `/\host` and `/<TAB>/host`.
- [ ] Sign-in is rate limited atomically, keyed on `REMOTE_ADDR`, with proxies configured on purpose.
- [ ] `user` and `csrf` reach templates through the render context; gates are route middleware.
- [ ] No `Cache-Control: public` response can carry the `Set-Cookie` that `SessionMiddleware` adds for visitors without a valid cookie.
- [ ] Tests sign in through the real form with `TestClient`.
