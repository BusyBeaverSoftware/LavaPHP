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
| `app/Commands.php` | optional | `function (CommandRegistry $c): void` | `invalid_config` |
| `config/app.php` | optional | `array` (string keys) | `invalid_config` |
| `config/logging.php` | optional | `array` (string keys) | `invalid_config` |
| `config/features.php` | optional | `array` with `define` / `set` sections | `invalid_config` |
| `config/.env` | optional | `KEY=VALUE` lines | `invalid_env_file` |
| `public/index.php` | entry point | — (the canonical one is in lavaphp/app) | — |
| `config/database.php` | optional | `array` (string keys) — **lavaphp/db** | `invalid_config` |
| `app/Database/Migrations/*.php` | optional | `return new class extends Migration {…};` — **lavaphp/db** | `invalid_migration_file` |

The last two rows are contributed by a pack rather than by core: the file path,
the expected shape, and the code all belong to `lavaphp/db`, and core never reads
them. The convention is the part that generalises — a pack declares a fixed
path under `app/` or `config/` and one problem code for a wrong shape, so an
agent learns where to look once per artifact and never has to read the pack's
source to find out. Core's own loader (`Boot/Steps/LoadPackConfig`) reads a
pack's declared `configFiles` at boot, which is why `config/database.php`'s keys
appear in `lava config` like any core key; the migration directory is read by
the pack's own commands instead, because nothing else in the framework has an
opinion about it.

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

## The map: `AGENTS.md`

The table above says what the framework reads. This says what it writes.

`lava map` compiles `AGENTS.md` at the app root from the app's own registries —
the Router's routes, the Container's ids, the Features registry, the Command
Registry — plus a framework reference and the fixed file list. There is no second
copy of those facts to keep in sync, which is why the document cannot disagree
with `lava routes` or `lava features`. `lava map --check` writes nothing and
answers whether the committed file is still accurate; `lava check` reports the
same verdict as a `stale_map` warning when the file exists.

Three properties make the file safe to commit, and each is a rule an agent can
rely on:

- **Environment-independent.** The document lists *declarations* — route paths,
  service ids, flag names, env var names — and never a resolved state. A flag's
  value depends on the environment; its existence does not. So `lava map` writes
  the same bytes under `--env=dev` and `--env=prod`.
- **No absolute paths.** Everything is relative to the app root, or `<pkg>:<rest>`
  for dependency code (`core:src/Boot/Kernel.php`), so the same app renders —
  and therefore hashes — identically whether core sits at `vendor/lavaphp/core/` or
  at `packages/core/`. An absolute path here would make the fingerprint
  machine-dependent, and a committed map stale on every other machine.
- **Fingerprint over facts.** The hash in the marker line covers the facts, not
  the rendered Markdown, so improving the renderer does not make every app's map
  stale. It is the first line, `<!-- lava:map hash=… -->`, and it is the whole
  comparison `--check` makes — no git, no timestamp, no diff.

The framework reference section teaches the canonical minimal form of every
artifact, held as constants in `Lava\Core\Map\FrameworkReference` and covered by
`packages/core/tests/Unit/FrameworkReferenceTest.php`, which parses each PHP
snippet and checks that every class and method it names exists. A snippet that
teaches a method the framework does not have fails the build.

`AGENTS.md` is the one generated artifact in the framework. It is never
hand-edited: change the app and run `lava map`.

## Naming rules

- Route names: `[a-z][a-z0-9_.]*`, globally unique, registered in
  `app/Routes.php` order (`users.show`, `beta.dashboard`).
- Feature names: `[a-z][a-z0-9_]*` (`beta_ui`).
- Param type names (custom `$r->pattern()` types): `[a-z][a-z0-9_]*`, distinct
  from the builtins `int`, `str`, `uuid`, `path`.
- Container ids: class-strings for objects, `dot.separated` for values.
- Config keys are addressed as `<file>.<key>` (`app.env`, `logging.level`).
- Migration files: `<YYYY_MM_DD_HHMMSS>_<snake_case>.php` — **lavaphp/db**. The
  timestamp is the ordering, so `db:new` writes the name and the caller supplies
  only a description.

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
- **A flag read during a request answers for that request's subject.**
  `App::handle()` binds the resolver to the subject and makes it current in
  `FeatureScope` for the whole dispatch, so a handler's `Features` parameter, a
  template's `feature()` and the router's `->when()` give one answer. Code built
  once — a singleton service, middleware — takes `FeatureScope` and calls
  `current()` when it runs; a `Features` taken in a constructor is boot's
  anonymous resolver.

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

Global middleware also wraps a request no route answers — an unknown path
(404), a known path with the wrong method (405), a body that does not parse
(400). The problem is thrown from where the handler would have been, so a
global layer can catch it and answer, exactly as it can a handler's problem;
what no layer catches renders as it always did. Route middleware runs only once
a route has matched.

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

## The CLI contract

Every command writes two views of one result: text for a human on a terminal,
and — under `--json` — a single envelope on stdout, which suppresses the text.
The envelope's `schema` field names the contract it obeys, and the contract is a
file: `lava.routes/1` means `docs/schemas/lava.routes/1.json`. The `/N` is frozen.
A breaking change to a payload means a new `/N`, never an edit — so an agent
that pinned a version keeps working, and `packages/core/tests/Schema/` fails the
build when a payload and its schema drift apart.

The numeral is per command, and `docs/schemas/` is the list of what exists — the
one place to look. Two commands are past `1`, and both are there because a
consumer that read the older file would mishandle a value the newer one carries:
`lava.check` is at `/2` because `map` was added to its `sections` enum when
`lava map` landed, and `lava.map` is at `/2` because `path` and `fingerprint`
are facts about the app — so an invocation that never read one (an undeclared
flag, `--help`, a failed boot) has no honest string for them, and `/1`'s
`string` described a payload the command has never produced. A widened enum and
a widened type are equally breaking here, for the same reason: the numeral is
how the consumer learns it must handle something new. `/1` was deleted rather
than kept beside each, because nothing could emit it and nothing had pinned it —
the first release was not tagged — so it would have been a schema file no code
can produce, which is a document that lies about what exists. The version has
one home, `Envelope::schema()`, so a bump cannot be half-applied.

- **Exit codes**: `0` ok, `1` a problem or a red result, `2` a malformed
  invocation (a typo'd command name, a bad flag value, a flag the command does
  not declare). Only `2` is about how the command was typed; `1` means it ran
  and the app is at fault.
- **A flag is accepted only if the command declares it, or the kernel reads
  it.** The kernel reads `--json`, `--quiet`, `--help` and `--env` on every
  command's behalf (`Command::UNIVERSAL_FLAGS`), so those four are declared by
  none and accepted by all; everything else must appear in the command's own
  `flags()`. `Args` parses any `--flag` it is handed, so without this check a
  typo (`lava routes --strct`) printed the table and exited `0` — the one
  mistake a CLI can silently swallow, and the one an agent with no muscle memory
  for a flag list makes most. The refusal names the flag, lists what the command
  does accept, and points at `lava <cmd> --help`. A flag after `--` is a
  positional argument and never reaches the check.
- **`data` keys are promised on every exit path**, including a failed boot. A
  consumer never branches on a shape that is only sometimes there.
- **`problems` is ordered to act on**: severity is the major key — fatals
  before warnings — and within one severity, runnable fixes (`Run: …`) first.
  Severity has to win: `stale_map`'s fix is a runnable command, but it is a
  warning, and sorting it above "your route does not compile" would hand an
  agent the cheapest task first and call it the most urgent.
- **A red test suite is not a problem.** `lava test` and `lava check` report it
  through `status` and the exit code and leave `problems[]` empty: the framework
  does not pronounce on code it never read.
- **`source.file` is an absolute path**, so a problem can be opened directly
  without guessing the app root. Line numbers are 1-based.

Test runners: `lava test` and `lava check` shell out to the app's OWN PHPUnit
(`<app>/vendor/bin/phpunit`) — the one its composer.json installed — rather than
to whatever the framework happens to carry. `LAVA_PHPUNIT` overrides the path
(the same escape-hatch idiom as `DB_TEST_DSN`), which is how a CI image or a
fixture app that has no `vendor/` still runs a real suite.

## Problems are the error model

Every failure — boot or runtime, HTTP or CLI — is a `LavaProblem`: one-sentence
what, imperative fix, JSON-safe context, the user artifact at fault (not the
framework's throw site), and a stable snake_case `code`. Problem reports
collect **all** problems in one pass; nothing fails fast and hides the rest.
Runtime 404/405 are problems too. Media: JSON for machines (the default when
no useful Accept header is present), the hand-escaped diagnostics page for
browsers; prod hides context, dev shows everything.