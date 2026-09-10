# LavaPHP conventions

The framework's laws. Every rule here exists for one of two reasons: it makes an
app predictable to an agent that reads it, or it makes a failure self-correcting.
If a change violates a law, the change is wrong.

## The laws

1. **Gating rule** — boot-lifetime resources (module services, CLI commands) gate
   at *boot*: a feature off means the thing is absent, and referencing the absent
   thing is a boot failure with an exact fix. Per-request resources (routes, route
   middleware) gate *per request*: off means a real 404.
2. **Reflection boundary** — reflection is allowed only at boot, read-only, in two
   places: reading a handler signature to build its injection plan, and reading a
   factory closure's file:line for introspection. Never at runtime. Never to
   construct objects. There is no container auto-wiring, period.
3. **One source of truth per fact** — routes live in the `Router`, services in the
   `Container`, flags in `Features`, commands in the `CommandRegistry`. CLI output
   and the generated `AGENTS.md` *compile* from these registries; there are no
   parallel hand-maintained docs to drift.
4. **No magic** — no facades, no global helper functions, no `functions.php`, no
   generated proxy classes, no wiring attributes, no service-locator statics.
   The only entry points are `public/index.php`, `bin/lava`, and the test harness.
5. **Boring PHP** — plain classes with constructors, explicit calls, `declare(strict_types=1)`
   everywhere. Anything a PHP-reading agent hasn't seen a thousand times needs
   a justification in this file.

## Fixed user-authored artifacts

An agent never has to search for where something is wired — the framework fixes
the locations:

| File | Contains |
|---|---|
| `app/Modules.php` | the list of pack modules to load (`ModuleRef` list) |
| `app/Services.php` | one function wiring every application service into the `Container` |
| `app/Routes.php` | one function registering every route on the `Router` |
| `app/Middleware.php` | the global middleware list, outermost first |
| `app/Database/Migrations/` | migration classes (with `lava/db`) |
| `config/app.php` | app config, keys addressed as `app.<key>` |
| `config/logging.php` | logging config, keys addressed as `logging.<key>` |
| `config/features.php` | feature-flag definitions (`define`) and overrides (`set`) |
| `config/.env` | environment values; never overrides real environment variables |

Missing optional files simply mean "none of that": no `app/Modules.php` means no
packs, no `app/Routes.php` means no routes. The framework boots zero-config.

## Deterministic orders (never guess, never rely on order you can't see)

- **Boot**: `Kernel::STEPS` — a public constant; the entire boot is one readable list.
- **Service registration**: core services (`Kernel::CORE_SERVICES`) → enabled
  modules in `app/Modules.php` order → `app/Services.php`. Re-registering an id
  is fatal (code `duplicate_service`).
- **Feature resolution**: definition (code default) → `config/features.php` `set`
  override → `LAVA_FEATURE_<UPPER_SNAKE>` env override → evaluation for
  (env, subject). Undefined names are fatal, never silently false. The full trace
  is returned in every `Resolution` and printed by `lava features resolve`.
- **Environment**: `LAVA_ENV` env var wins over `config/app.php` `'env'`, which
  wins over the default `'dev'`. Conventional values: `dev`, `test`, `prod`.

## Names

- Route names: `users.show` (dot-separated, mandatory, globally unique).
- Feature names: `snake_case`, `[a-z][a-z0-9_]*`.
- Config keys: `<file>.<key>` — `app.base_url` comes from `config/app.php`.
- Env overrides for features: `LAVA_FEATURE_<UPPER_SNAKE>` — `LAVA_FEATURE_BETA_UI`.
- CLI pack commands are prefixed (`db:status`); core commands are not.

## Deliberate redundancy

`app/Modules.php` (`ModuleRef`) repeats the `package` and `feature` that each
pack's `PackInfo` also declares. This is not an accident: when a pack is *missing*
its code cannot load, so the duplicate metadata is the only thing that lets the
boot report say exactly `composer require lava/db`. Boot cross-checks both
sources; any mismatch is fatal (code `module_mismatch`).

## What LavaPHP owns vs. delegates

Owns (agent-facing, pure logic): router, container, config, dotenv, validation,
console, errors, feature flags, migrations, query builder, middleware pipeline.
Delegates (CVE surface, value in internals): PSR-7 messages (nyholm), HTML
escaping (Twig, optional), DB drivers (PDO), crypto (PHP core). PSR-3/4/7/11/15
interfaces at every edge — any compliant library plugs in.