# Decisions

Written as the app was built, under a standing instruction to choose reasonably
and record the reasoning rather than stop and ask. Each entry is a decision that
had more than one defensible answer, the answer chosen, and what it traded away.
Where a decision was forced by a framework limitation, the limitation is named —
those are the entries that double as feedback for `lava/core` and its packs.

## D1 — Authentication is hand-written rather than a dependency

**Chosen:** sessions as HMAC-SHA256 signed cookies, in `app/Auth/`.

There is no auth pack, and pulling in a general-purpose PHP auth library would
have made this app a demonstration of *that* library rather than of LavaPHP.
The trade is real and worth stating: a hand-written auth layer is the highest-
consequence code in the repo, and it has no upstream to inherit fixes from.

**Mitigation, not a resolution:** everything the design claims is asserted in
`tests/AuthTest.php` — nonce rotation on login (session fixation), identical
status *and* identical message for a wrong password and an unknown account
(non-disclosure), `HttpOnly`/`SameSite` on the cookie, a tampered cookie
degrading to a stranger rather than an error. The login timing oracle is closed
in `PasswordHasher::verify()` by comparing against a decoy bcrypt hash when the
account is unknown; that one is deliberately *not* asserted, because a
wall-clock assertion in CI is flaky and a test that fails at random teaches
people to ignore the suite.

## D2 — CSRF token == the session nonce, not a second value

**Chosen:** the session payload carries a `csrf` nonce and that nonce *is* the
token; verifying is `hash_equals`.

One value to rotate, so session fixation and CSRF share a single mechanism
instead of two that can drift apart. The cost: the token is stable for the life
of a session rather than per-form or per-request, so a token leaked once stays
valid until the session ends. Accepted because the cookie is `HttpOnly` and
`SameSite=Lax`, and because per-form tokens would need server-side state this
app deliberately does not have.

## D3 — A draft others cannot see is a 404, not a 403

**Chosen:** `PostRepository::visibleById()` filters by viewer, so an invisible
row is indistinguishable from a missing one.

A 403 on a draft confirms the post exists, which turns `/posts/{id}` into an
id oracle. Where the row *is* visible and the caller simply may not change it,
the answer is a 403 — there is nothing left to hide at that point, and
"not yours" is the more useful sentence. The rule is therefore about visibility
rather than about permission: **404 when the caller cannot see it, 403 when they
can see it but cannot change it.**

## D4 — The list route is `/`, and there is no `GET /posts`

**Chosen:** `/` is the list. `/posts/{id}` is a post. There is no bare
`GET /posts` index route.

`/posts` reads like an index but is where writes go, and a path that is `GET`-less
on purpose is less surprising than one that means "the list" when read and "create
something" when written. The JSON twin lives at `/api/posts`, where `GET` and
`POST` on one path are exactly what an agent expects.

## D5 — Two auth gates, not one with a branch

**Chosen:** `RequireLoginMiddleware` redirects to `/login?next=…`;
`RequireLoginJsonMiddleware` returns a 401 envelope. Each is attached to the
routes that need it.

One middleware branching on `Accept` would have been fewer classes, but the
branch would be hidden in the middleware and invisible from `app/Routes.php` —
where "which requests need a user" is the thing a reader is looking for. The
cost is that the rule "an unauthenticated write is refused" now lives in two
places. `FormErrors` makes the same trade for the same reason: the media a
failure is rendered in is decided where the route is declared, not discovered
later.

## D6 — An app-owned error page for handler problems only

**Chosen:** `ErrorPageMiddleware` (innermost global) catches thrown problems and
renders one of this app's pages for HTML requests and 4xx; everything else is
re-thrown untouched.

The framework's diagnostics page is correct and useful — to a developer — and a
visitor to a blog should not be shown a page headed *"LavaPHP found 1 problem"*.
The middleware is narrow on purpose: 5xx is re-thrown because a bug is exactly
when the framework's file/line/context page is the better answer, and non-HTML
requests are re-thrown so `/api/*` and `curl` keep their `{"problems": […]}`
envelope. It narrows nothing; it adds a page for the one audience the default
renderer cannot serve well.

**It covers the requests no route answers, too.** `lava/core` throws an unknown
path (404), a wrong method (405) and an unparsable body (400) through the global
middleware, so a mistyped URL gets the same page as a missing post. A page
rendered here instead of by the framework has to re-add the 405's `Allow`
header itself, and does.

## D7 — The layout's `user` and `csrf` arrive via `Page::context()`

**Chosen:** one static helper every render call goes through, merging `user` and
`csrf` *last* so a caller cannot shadow them.

The layout needs both on every page (its sign-out control is a CSRF-protected
form). A Twig global looks like the tool, and `ViewRenderer::environment()` would
let the app add one — but the renderer is a singleton and these are request
state. A global set for one request is still set for the next request the same
process serves, which in a long-running worker, or a test process that boots
once, shows one visitor's name to another. An explicit context cannot leak that
way, and `strict_variables` turns a call site that forgets it into a render
error rather than a blank. Merging last is the load-bearing part: the values
must not be overridable, or the guarantee is worth nothing.

## D8 — Password confirmation is a `custom()` rule that reads the other field

**Chosen:** `password_confirm` carries `custom('matches_password', …)`, whose
closure reads `password` from the form payload it closes over.

A `lava/validate` rule is handed only its own field's value, so a cross-field
rule is a closure over the payload — the pattern the validator's own
documentation gives for "the end date is after the start date". The closure
passes when `password` is not a string, because that field's own rules already
refuse it and a second complaint about a comparison that never happened is
noise. The result is an ordinary `validation_failed` problem on
`password_confirm`, shown beside that field like any other.

An earlier version compared the two in the handler through an app-owned problem
class, written in the belief that the validator could not express the rule. It
could; the idiom was documented and missed.

**A mistake worth recording:** that app-owned problem's message once explained
*why* the rule lived outside the validator — a note for whoever reads the code —
and the form rendered it to the person who had just mistyped their confirmation
field. `LavaProblem` has three fields for three audiences (`problem` → the user,
`fix` → the maintainer, `context` → data), and blurring them is easy. `AuthTest`
still asserts that the string `lava/validate` never appears on a form.

## D9 — Sessions are revoked by epoch, not by deleting rows

**Chosen:** `users.session_epoch` is compared against the cookie on every
request; `changePassword()` bumps it, `rehash()` deliberately does not.

The cookie is signed and self-contained, so without this a stolen cookie stays
valid until it expires — there is no server-side session table to delete. Bumping
the epoch on a password change kills every session at once, which is the
behaviour a user expects from "change my password".

`rehash()` not bumping is the deliberate half: nobody's credential changed when
the server merely got faster, and logging someone out of every device for that
would be a security measure punishing the wrong person. Both directions are
tested, because either could be implemented backwards.

## D10 — Tests boot against `sqlite::memory:`

**Chosen:** an in-memory database per test process, migrated in
`setUpBeforeClass()`, tables truncated in `setUp()`.

Sharing the development `var/blog.sqlite` would make the suite write test users
into whatever the developer was looking at, and pass or fail depending on what
was already there. An in-memory database is empty by construction, so a
uniqueness test tests the constraint and not the leftovers.

Tests sign in through the real form rather than fabricating a signed cookie — a
fabricated one would prove the signer round-trips and nothing about signing in.
`TestClient` keeps cookies between requests the way a browser does, so a client
that registered stays signed in; `tests/Support/Browser.php` adds only this
app's test vocabulary on top (a browser's `Accept`, reading the CSRF token off a
form, signing up and in).
