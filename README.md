# LavaPHP

An open-source PHP framework designed for **agent-first usage**: an AI agent (or a human who likes explicit code) can read the entire core, verify its work in one command, understand any app from one generated file, and self-correct from a single error message.

## The four pillars

1. **Conventions & zero magic** — one canonical way to do each thing. No facades, no auto-wiring, no generated proxies, no container magic. Every wire is visible in `app/Services.php`, `app/Routes.php`, `app/Modules.php`, `config/*.php`.
2. **Agent-first CLI** — every `lava` command emits stable, schema-versioned JSON (`--json`). `lava check` is the one-command verification loop.
3. **Generated project map** — `lava map` compiles the running app into `AGENTS.md` (routes, services, features, env vars, schema). A build artifact, like a lockfile: always current, never hand-edited.
4. **Errors as a feedback loop** — every framework failure is a `LavaProblem` carrying *what* failed, *where* (file:line), the *failing input*, and an *imperative fix*. One error message = one self-correction round trip.

LavaPHP is a small core plus hard-decoupled packs (`lavaphp/db`, `lavaphp/validate`, `lavaphp/view`, `lavaphp/http-client`, `lavaphp/events`), each a separate Composer package depending only on core. A deterministic feature-flag system gates every pack at boot and app features per request — and the app *tells you* when a pack is missing, with the exact command to install it.

## Repository layout

```
packages/core        lavaphp/core        — the kernel: boot, DI, routing, feature flags, console, errors
packages/db          lavaphp/db          — query builder, schema DSL, migrations (PDO)
packages/validate    lavaphp/validate    — typed validation DSL
packages/view        lavaphp/view        — Twig integration
packages/http-client lavaphp/http-client — small HTTP client (ext-curl)
packages/events      lavaphp/events      — PSR-14 events, listeners declared in app/Listeners.php
packages/app         lavaphp/app         — application skeleton (composer create-project target)
apps/demo            — dogfood demo app, the canonical example
apps/blog            — a blog with sign-in, built the way a consumer builds one
docs/                — conventions, per-pack docs, sessions and CSRF, mail, translation, uploads, stable JSON schemas for every CLI command
```

## Installing

An app starts from the skeleton on Packagist:

```sh
composer create-project lavaphp/app my-app
cd my-app
./vendor/bin/lava check
```

and a pack is one `composer require` away: `lavaphp/db`, `lavaphp/validate`,
`lavaphp/view`, `lavaphp/http-client`, `lavaphp/events`. Each package is published from a
read-only mirror of its directory, because Packagist reads `composer.json` only
at a repository root — [docs/releasing.md](docs/releasing.md#publishing) has how.

### Upgrading from 0.6 to 0.7

`0.7.0` is what a fourth outside build asked for. An agent that had never seen the
framework built an admin dashboard and a blog on `0.6.0`, filed four bugs and ten
gaps, and two of its findings ask something of an app. Require every `lavaphp/*`
package at `^0.7.0` together, then:

- **A JSON request body that is a list is now refused** with `malformed_body`
  (400), which is what `docs/problem-codes.md` and the validate pack's page always
  said happens to "valid JSON that is not an object" — the code checked
  `is_array()`, and a list passes that. An endpoint that accepted a bare array now
  needs the collection sent under a name. An empty `[]` is still accepted, because
  `{}` decodes to the same value.
- **`lava api --json` is `lava.api/2`.** The payload gained `properties`,
  `constructor`, `abstract` and `matched`, and `mode` gained `property`. Re-pin if
  you read that envelope; `1.json` is deleted, as this project's rule requires.
- **Nothing else to do, and four things to use.** `lava api` now answers about
  public properties (178 of them, which a codebase of `final readonly` value
  objects mostly consists of), constructors, and abstract classes — the three
  questions the build could not get answers to, one of which cost it a failed boot
  guessing `$ctx->dir` instead of `$ctx->appDir`. `Responses::of($body, $type)`
  sends a content type, so serving a feed or an image no longer means reaching past
  the framework to a PSR-17 factory. `TestClient` can send an upload, raw bytes, or
  a request the test built itself — `docs/uploads.md` finally has a Testing
  section. And a new project ships a `.gitignore`, so its first commit no longer
  takes `vendor/` and the `.env` holding its session secret.

The reasoning for each is in [DECISIONS.md](DECISIONS.md), entries 347–351.

### Upgrading from 0.5 to 0.6

**Upgrade promptly if you are on `0.5.0`.** A security review of the whole
framework found two high-severity faults introduced in that release, plus
several older ones. `0.5.0` decoded the request path for routing but left the
request carrying the original, so a middleware guarding `/admin` by prefix never
saw `/%61dmin` while the router routed it — an authorization bypass — and the
same decode delivered `%2e%2e%2f` into a `{rest:path}` param as `../`, a remote
path traversal. Neither existed in `0.4.1`. Require every `lavaphp/*` package at
`^0.6.0` together, then:

- **A request path with a `..` segment or a control byte is now refused**
  (`bad_request_path`, 400) instead of routed. A test asserting 404 for a
  traversal attempt asserts 400 now — that was the only change the reference
  blog needed.
- **A production 5xx no longer carries the problem's sentence or fix**, only its
  `code` and `severity`. Messages had been built from exactly what redaction
  exists to withhold: absolute template directories, the database driver's
  sentence, an upstream URL with its query string. `dev` is unchanged, and a 4xx
  keeps its detail, because that is the caller's own mistake.
- **Check your `->regex()` rules.** PCRE's `$` also matches before a final
  newline, so every anchored pattern in the framework — including the one that
  validates your rules — accepted a trailing newline. They are anchored with
  `\z` now: a value that validated with a newline on the end will start failing.
- **Table names are validated like column names**, and a literal default
  containing a backslash is refused in a migration.
- **The HTTP client refuses more.** A response over 8 MiB is
  `response_too_large` (raise `http_client.max_response_bytes` if you fetch
  bigger); a method or header containing CR or LF is `unsendable_request`; the
  transport speaks only http and https; a body that cannot be rewound is no
  longer resent on a retry. Redaction now masks `client_secret`,
  `refresh_token` and every other name it used to miss.
- **Every response carries `X-Content-Type-Options: nosniff`**, and JSON bodies
  escape `<` and `&` so they are safe to embed in a page.
- **Secrets stay out of your logs.** A malformed `config/.env` line reports its
  key and never its value, and `lava describe` and `lava config` redact exactly
  what `lava env` does — at any depth.

The findings, and what each fix does, are DECISIONS.md entries 329–344.

### Upgrading from 0.4 to 0.5

`0.5.0` is the round-3 release: it fixes what agents building on `0.4` found,
and adds the command they most needed. Require every `lavaphp/*` package at
`^0.5.0` together, then:

- **Run `lava map` once.** The events pack's registration lines moved, the map
  is now compiled as if every installed pack's gate were on (so a committed
  `AGENTS.md` written with a pack switched off read stale on every other
  machine), and `lava api` is a new command, which the map counts.
- **Route parameters are encoded once, at both ends.** `url()` percent-encodes
  values and static segments, and the request path is decoded once before
  matching, so a handler receives `a b` where it used to receive `a%20b`, a
  literal `/café` route finally matches, and generated `href`s change bytes. An
  app that decoded a route param itself must stop; a custom param type written
  around the encoded form (`[a-z0-9%]+`) must be rewritten around the value.
- **Re-pin the CLI payloads you read.** `lava routes --json` is now
  `lava.routes/2` (rows say where a redirect leads), `lava about --json` is
  `lava.about/2` (package versions and a pack's own facts, such as pending
  migrations), and `lava events --json` is `lava.events/2` (listeners come back
  in the order dispatch takes, each with its phase). The superseded schema files
  are deleted, as this project's rule requires.
- **Alias columns that differ only in case.** `select('posts.ID', 'users.id')`
  is now `bad_query`: SQLite returned one column and silently dropped the other
  value. Alias one of them, as the printed fix does.
- **Three new refusals at boot.** A redirect whose path matches its target's own
  URLs (it would answer that address with itself, or never match at all); a
  listener registered with `$c->factory()` (a listener is built once and shared,
  so register it with `singleton()`); and a listener given two different phases.
  Each names the line to edit.
- **An oversized form post is a 413.** A form larger than PHP's `post_max_size`
  arrives with every field missing, which used to surface as a validation error
  blaming a field the visitor did fill in. It is now `request_too_large`,
  refused before routing.
- **Nothing to do, and plenty to use.** `lava api` indexes the framework's own
  public API — `lava api <ClassName>`, `lava api <methodName>`,
  `lava api --search=<term>`, all with `--json` — so "does the framework have
  something for this?" is one command rather than a grep through `vendor/`.
  Listener order can now be stated with `Lava\Events\Phase::first()` and
  `Phase::last()`, one boot reports every mistake in `app/Listeners.php` instead
  of the first, `ViewRenderer::namespaces()` lists the declared Twig namespaces,
  and `TestApp`/`TestConsole` put back environment variables a run removed.

The reasoning for each is in [DECISIONS.md](DECISIONS.md), entries 314–324.

### Upgrading from 0.4.0 to 0.4.1

`0.4.1` fixes what Lava Notes' third review found, and asks nothing of an app:
`composer update 'lavaphp/*'`, and a committed `AGENTS.md` stays current.
The open redirect below is advisory
[GHSA-x76q-3p93-qcc2](https://github.com/BusyBeaverSoftware/LavaPHP/security/advisories/GHSA-x76q-3p93-qcc2).

- **An open redirect is closed.** In 0.4.0, a route whose path starts with a
  param could be given a value starting with `/` or `\`, and `url()` built
  `//evil.example/x` from it, which a browser reads as another site. A
  `$r->redirect()` into such a route sent visitors there:
  `/docs//evil.example/x` answered `Location: //evil.example/x`. A generated URL
  now percent-encodes that one character. Update any app with a route whose path
  starts with a param.
- **Redirects.** One whose target is gated off by `->when()` is a 404 rather
  than a 301 into one, and one that would lead to the address it was asked for
  fails with `bad_redirect` at its line rather than looping.
- **Events.** A listener may depend on a service that dispatches its event,
  which was `circular_service`, and an error in `app/Listeners.php` is reported
  at its line.
- **Results that were wrong.** `Connection::count()` no longer fails on a query
  ordered by a select alias, and `TestApp` and `TestConsole` give back the
  environment variables a run removed.
- **Problems that say more.** Every route problem carries its line; a handler's
  unregistered service names its route, and says to turn a switched-off pack
  on; a service cycle is reported once; view config problems point at
  `config/view.php`; `select()`'s same-name fix is a call that works; and
  `Flag::env()` refuses a branch that is not a Flag.
- **New, and additive.** `lava describe` names a redirect's target,
  `ViewRenderer::namespaces()` lists the declared namespaces, and
  `Router::declaredAt()` says where a route was registered.

The reasoning for each is in [DECISIONS.md](DECISIONS.md), entries 296–311.

### Upgrading from 0.3 to 0.4

`0.4.0` adds more than it changes, but two things ask something of an app.
Require every `lavaphp/*` package at `^0.4.0` together, then:

- **Run `lava map` once.** Core registers one more service,
  `Lava\Core\Boot\RuntimeFacts`, and the lines that register core's services
  moved, so a committed `AGENTS.md` reads stale until it is regenerated.
- **Alias columns that share a name in `select()`.** `select('posts.id',
  'users.id')` used to return one `id`, whichever came last; it is now
  `bad_query`. Alias one of them: `select('posts.id', ['user_id' => 'users.id'])`.
- **Nothing to do, and something to use.** URL generation works for `{name:str}`
  params, which 0.3.0 refused for every value, and a custom route type may contain
  `/` or `#`. A route whose custom types do not compile together is now refused
  at boot instead of missing on every request. New: `$r->redirect()` routes,
  `RouteArgs::of($request)` for middleware, `Schema::dropIndex()`,
  `Connection::count()` and alias maps in `select()`, `namespaces` and
  `extensions` in `config/view.php`, `RuntimeFacts` for a status page,
  `TestConsole(replace:)`, and the `lavaphp/events` pack.

The reasoning for each is in [DECISIONS.md](DECISIONS.md), entries 282–294.

### Upgrading from 0.2 to 0.3

`0.3.0` fixes results that were silently wrong, and some of the fixes are
refusals. Require every `lavaphp/*` package at `^0.3.0` together, then:

- **Run `lava map` once.** An alias row now names where `alias()` was called
  rather than where its target was registered — core's default
  `LoggerInterface` among them — and a factory declaring `self` or `static` shows
  its class, so a committed `AGENTS.md` reads stale until it is regenerated.
- **Move expressions out of every column position.** `where*()`, `orderBy()`,
  both sides of a join and its table, and the keys of `insert()` and `update()`
  now refuse what is not a column name with `bad_query`, as `select()` already
  did. Write those with `whereRaw()`, `Connection::query()` or
  `Connection::statement()`. Names may now use any letter and a schema prefix
  (`prénom`, `main.users.name`).
- **Check migrations that call `Schema::table()` with indexes.** `index()`,
  `unique()` and a column's `->unique()` are created now; before, they were
  dropped without a word. A database migrated on 0.2 does not have them, so add
  them in a new migration, and expect a unique index over rows that already
  collide to fail. `primary()` in `table()` is refused.
- **Read a boot failure's details from its context.** `unexpected_failure` at
  boot and a config file that throws no longer put the exception's message in the
  sentence or an absolute path in the fix; both are in `context`, which
  `lava check` prints. A test asserting on the old sentence reads
  `context['message']` instead.
- **Copy the new block in `public/index.php`** if you want it: in `prod` a boot
  failure's report is written to the server's error log, since the response no
  longer carries it.
- **Nothing to do.** A request failure now names the app's own line as `source`
  and carries a short trace outside `prod`; the default logger prints the
  exception; problem JSON with invalid UTF-8 is substituted instead of throwing;
  a `@namespace/…` template that is missing names that namespace's directories;
  and `invalid_command_name` never suggests a name another command has.

The reasoning for each is in [DECISIONS.md](DECISIONS.md), entries 270–280.

### Upgrading from 0.1 to 0.2

`0.2.0` changes things an app may have to act on. Require every `lavaphp/*`
package at `^0.2.0` together, then:

- **Run `lava map` once.** The services section now lists what each registration
  declares, and core registers two more ids, so a committed `AGENTS.md` reads
  stale until it is regenerated.
- **Rename any command with a hyphen, underscore, capital or space** in its name.
  It still runs, but `lava check` warns (`invalid_command_name`) with the new
  name, and `--strict` fails until the rename is made.
- **Move expressions out of `select()`.** It accepts column names only now;
  `COUNT(*)` and friends go through `Connection::query()`.
- **Read production 5xx details from the log.** A server fault's JSON in `prod`
  keeps its code, message and fix but sends `context` as `{}` and `source` as
  null; the app writes the whole problem to its `LoggerInterface`.
- **Nothing to do, and one thing to undo.** A handler exception now renders as a
  500 `unexpected_failure` instead of escaping. An app may now register its own
  `LoggerInterface` or `ClockInterface`: core only fills them when empty. A test
  double kept in `app/Services.php` behind `LAVA_ENV=test` can move to
  `TestApp::boot($dir, replace: [...])`.

The reasoning for each is in [DECISIONS.md](DECISIONS.md), entries 257–268.

To work on the framework itself, clone the repository:

```sh
git clone https://github.com/BusyBeaverSoftware/LavaPHP.git
cd LavaPHP
composer install            # the monorepo: every pack, plus the test suite
```

To watch the framework verify itself, run the demo — the canonical app, and the
one place the packs are exercised together:

```sh
cd apps/demo
composer install            # the demo is an APP: it gets its own vendor/
./vendor/bin/lava check
```

The second `composer install` is not optional. `apps/*/vendor/` is gitignored
and the monorepo's install fills only the root `vendor/`, so `apps/demo/vendor`
does not exist until you run it — and it must be the demo's own install, because
the app's `App\` namespace and its pack wiring are only in *its* autoloader.

## Development

```
composer install     # installs all packs via path repositories
composer verify      # phpunit (all suites) + phpstan level 8 + lavaphp/core at max
composer coverage    # per-pack line coverage, floors enforced
composer check:floor # every tracked file parses on the oldest PHP we claim to support
```

`composer coverage` is deliberately not part of `verify`: it needs a coverage
driver (pcov) and `pdo_sqlite`, and a gate that cannot run on a fresh checkout
is a gate that gets skipped. It measures the `packages/*/src` trees, counts the
`bin/lava` subprocesses the end-to-end tests spawn, and fails below a floor per
pack — see [`tools/coverage-check.php`](tools/coverage-check.php) for the
numbers, the floors, and why `db` reads 55% until the subprocesses are counted.

`composer check:floor` is not part of `verify` either, for the same reason: it
needs a PHP of the floored version, which it takes from docker unless the host
already is one. It reads the floor out of `composer.json`'s `require.php` and
lints every tracked file with it, reporting **every** file that does not parse
rather than the first. PHP 8.4 syntax is a parse error on 8.3, and a parse
error takes down the whole file — CI's 8.3 job found three of them one push at
a time, because PHPUnit stops at the first test file it cannot compile. See
[`tools/php-floor-check.php`](tools/php-floor-check.php), which also records why
php-parser cannot do this job.

Status: pre-release (0.x under active development). PHP `^8.3`.

## License

MIT — see [LICENSE](LICENSE).