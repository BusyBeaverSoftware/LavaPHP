# LavaPHP conventions

The rules an agent can rely on everywhere. Every rule here is enforced by the
framework at boot — a violation is a problem with a fix, not a silent failure.
Problem codes live in [problem-codes.md](problem-codes.md).

## Fixed user-authored artifacts

An agent always knows where to look. Missing optional files mean "none of
that" — zero-config apps are valid and boot green.

| File | Required | Returns | Wrong shape is |
|---|---|---|---|
| `app/Modules.php` | optional | `list<ModuleRef>` | `invalid_config` |
| `app/Services.php` | optional | `function (Container $c, AppContext $ctx): void` | `invalid_config` |
| `app/Routes.php` | optional | `function (Router $r): void` | `invalid_config` |
| `app/Middleware.php` | optional | `list<class-string>` of PSR-15 middleware | `invalid_config` |
| `config/app.php` | optional | `array` (string keys) | `invalid_config` |
| `config/logging.php` | optional | `array` (string keys) | `invalid_config` |
| `config/features.php` | optional | `array` with `define` / `set` sections | `invalid_config` |
| `config/.env` | optional | `KEY=VALUE` lines | `invalid_env_file` |
| `public/index.php` | entry point | — (the canonical one is in lava/app) | — |

An **app root** is any directory containing at least one of `app/`, `config/`,
or `public/index.php`. That is the whole test — boot checks it first and
reports `not_an_app` if none is present. The rule is deliberately generous
(any one marker suffices) because every artifact above is optional: a
config-only app, and a zero-config app that is just `public/index.php`, are
both legitimate. It exists because the opposite failure is silent — booting a
directory that is not an app otherwise succeeds with an empty app, and
`lava routes` there answers `status: ok, routes: []`.

`app/Classes/` autoload `App\` → `app/…` (real apps get this from their
composer.json; fixture apps get it from the test harness). Function handlers
are **not** autoloadable — require their file at the top of `app/Routes.php`.

## Naming rules

- Route names: `[a-z][a-z0-9_.]*`, globally unique, registered in
  `app/Routes.php` order (`users.show`, `beta.dashboard`).
- Feature names: `[a-z][a-z0-9_]*` (`beta_ui`).
- Param type names (custom `$r->pattern()` types): `[a-z][a-z0-9_]*`, distinct
  from the builtins `int`, `str`, `uuid`, `path`.
- Container ids: class-strings for objects, `dot.separated` for values.
- Config keys are addressed as `<file>.<key>` (`app.env`, `logging.level`).

## Resolution orders (deterministic, documented, no fallbacks)

- **Environment value**: real environment variable → `config/.env` → absent.
  `.env` never overrides a real variable. `LAVA_ENV` decides env, then
  `config/app.php`'s `env` key, then `dev`.
- **Feature flag setting**: code default (`Feature::define`) → `config/features.php`
  `set` → `LAVA_FEATURE_<UPPER_SNAKE>` env var → evaluation for (env, subject).
  Undefined names are fatal with a nearest-name hint — never a silent false.
- **Service registration order**: `Kernel::CORE_SERVICES` (a public const,
  drift-guarded by a test) → enabled modules in `app/Modules.php` order →
  `app/Services.php`. Re-registering an id is fatal.

## The gating rule

- **Boot-lifetime resources** (module services, CLI commands): gated at boot —
  off means absent. Referencing the absent thing is a boot failure with an
  exact fix. Gating these to an audience flag (rollout/users) is `invalid_gating`.
- **Per-request resources** (routes, route middleware): gated per request —
  off means a real 404, never a 503. Audience flags are at home here.
  With no `Features` available, gated routes are off (fail-closed).
- A `->when()` gate naming an undefined flag is a **boot** failure (the classic
  silent-404 typo), even though the gate is evaluated per request.
- Audience flags (rollout/users) need a **subject**: register a
  `FlagSubjectResolver` in `app/Services.php` under `FlagSubjectResolver::class`
  — the container id is the interface itself. Not registering one is valid;
  every audience flag then resolves off for every request (fail-closed).
  Anonymous requests (resolver returns null) always resolve audience flags
  OFF — no anonymous bucketing, by documented policy.

## The reflection boundary

Reflection happens only at boot, read-only, in exactly two places:

1. handler signatures → injection plans (`HandlerInvoker::plan`, frozen onto
   the router; dispatch is pure lookup + call);
2. factory closures → file:line for introspection.

Never at runtime, never to construct objects, never for auto-wiring. The
container is explicit registrations only.

## The handler contract

`[ClassName::class, 'method']` or `'function_name'`, validated at boot:

- the class is concrete and its constructor has **no required parameters** —
  dependencies arrive as typed method parameters, which is what makes the full
  dependency story of a route visible in `lava routes --json`;
- every parameter is typed exactly `ServerRequestInterface` (or
  `RequestInterface`), `RouteArgs`, or a registered container id;
- untyped / built-in / union / variadic / defaulted parameters: `bad_handler`;
- the return type is declared `\Psr\Http\Message\ResponseInterface` (not
  nullable, not a subclass of something else) — build responses with
  `Responses::json() / text() / html() / redirect() / noContent()`.

Middleware: PSR-15 class-strings, resolved from the container at request time
(register them in `app/Services.php`, validated at boot). Lists are
outermost-first: `app/Middleware.php` (global) wraps route middleware.

## Route paths

`/users/{id:int}` — every param has an explicit type. Builtins: `int`
(`\d+`), `str` (one segment), `uuid`, `path` (spans slashes). Custom types via
`$r->pattern('word', '[a-z]+')` before first use. HEAD is never auto-mapped to
GET — declare `Method::Head` if a route should answer HEAD. URL generation
(`UrlGenerator::url()`) validates every value against its param type: a
generated URL can never point at a path the router wouldn't match.

Routes register in order: `app/Routes.php` first, then each enabled module's
`routes()` in `app/Modules.php` order — on any path overlap the app's
registration matches first, so an app can always override a pack route by
registering the same path. A gated-off pack's routes are absent (real 404),
not disabled-in-place.

## Problems are the error model

Every failure — boot or runtime, HTTP or CLI — is a `LavaProblem`: one-sentence
what, imperative fix, JSON-safe context, the user artifact at fault (not the
framework's throw site), and a stable snake_case `code`. Problem reports
collect **all** problems in one pass; nothing fails fast and hides the rest.
Runtime 404/405 are problems too. Media: JSON for machines (the default when
no useful Accept header is present), the hand-escaped diagnostics page for
browsers; prod hides context, dev shows everything.