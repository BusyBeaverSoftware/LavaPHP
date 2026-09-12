# Lava Blog

A blog with sign-in, built on LavaPHP. It is the second example app beside
[`apps/demo`](../demo), and a different kind: the demo is the framework's own
dogfood — every pack, every pillar, an API first — while this one was written
the way someone outside the monorepo writes an ordinary website, and kept for
what that turned up. Four changes to `lava/core` came out of it (see
[What building it changed](#what-building-it-changed)), and
[`DECISIONS.md`](DECISIONS.md) records the design choices and what they traded
away.

## Running it

```sh
cd apps/blog
composer install

# One secret, and the app will not boot without it:
cp config/.env.example config/.env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # paste into SESSION_SECRET

./vendor/bin/lava db:migrate
./vendor/bin/lava serve
```

Then open <http://127.0.0.1:8080> and register.

```sh
./vendor/bin/phpunit      # the suite, against sqlite::memory:
./vendor/bin/lava check   # boot, wiring, routes, features, env, tests, AGENTS.md freshness
```

## What it does

Public read, authenticated write. Anyone can read published posts; signing up is
a flag (`signups_open`) that can be switched off without touching a route.
Signing in unlocks writing. A post is either published or a draft, and a draft
is visible only to its author — as a 404, not a 403, to anyone else.

```
GET  /                     the published posts
GET  /posts/{id:int}       one post
GET  /posts/new            the editor          (signed in)
GET  /posts/{id:int}/edit  the editor          (author only)
GET  /login  /register     the forms
GET  /api/posts            the same posts, as JSON
```

Writes are `POST /posts`, `POST /posts/{id:int}`, `POST /posts/{id:int}/delete`,
`POST /login`, `POST /register`, `POST /logout` — and `POST /api/posts`. Every
one of them is CSRF-checked. Signing out is a POST for the same reason: a GET
logout is fireable by an `<img src>` on any page on the internet.

Every 4xx a browser can meet is one of this blog's pages, and every 4xx an agent
can meet is the framework's `{"problems": […]}` envelope — including a mistyped
URL, which no route answers.

## What building it changed

The blog was first built outside this repository against the `0.1.0` packs,
with no changes to the framework, and its friction was written down. Checked
against the source afterwards, much of what that report called a limitation
already had an answer: cross-field validation is a `custom()` rule closing over
the payload, each pack's config file is documented key by key, and an unset
required environment variable fails `lava check --strict`. What had no answer is
now part of `lava/core`:

- **A request no route answers passes through the global middleware.** An
  unknown path, a wrong method or an unparsable body is thrown from where the
  handler would have been, so `ErrorPageMiddleware` renders a mistyped URL as
  this blog's own 404. Before, only a missing *record* could get that page.
- **`TestClient` keeps cookies between requests.** A test registers through the
  real form and stays signed in. `tests/Support/Browser.php` used to carry a
  cookie jar of its own to make that possible.
- **A broken service is reported once.** Booting this app without its secret
  printed the same problem once for every service that depends on the session
  cookie.
- **A local `config/.env` no longer changes `AGENTS.md`.** Copying
  `.env.example` to `.env` used to make the committed map stale on that machine
  alone.

The root [`DECISIONS.md`](../../DECISIONS.md), entries 234–240, has the reasoning.

## What is deliberately absent

No library was added to cover authentication, because LavaPHP ships none —
v1 has no auth pack. So sessions are HMAC-SHA256 signed cookies
(`app/Auth/SessionCookie.php`), CSRF is the session's own nonce, session
fixation is handled by rotating that nonce on login, and revocation is a
`session_epoch` column compared per request. That is the app's responsibility
and it is the part of this repo most worth reading critically: `tests/AuthTest.php`
exists to hold each of those claims to account, because a hand-written auth
layer with no tests is a hand-written auth layer with no guarantees.

No ORM, no templating beyond `lava/view`, no auto-wiring. `app/Services.php` is
the complete answer to "where does this come from?", and a handler parameter can
only be the request, `RouteArgs`, or something registered there.
