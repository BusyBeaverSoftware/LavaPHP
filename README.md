# LavaPHP

An open-source PHP framework designed for **agent-first usage**: an AI agent (or a human who likes explicit code) can read the entire core, verify its work in one command, understand any app from one generated file, and self-correct from a single error message.

## The four pillars

1. **Conventions & zero magic** — one canonical way to do each thing. No facades, no auto-wiring, no generated proxies, no container magic. Every wire is visible in `app/Services.php`, `app/Routes.php`, `app/Modules.php`, `config/*.php`.
2. **Agent-first CLI** — every `lava` command emits stable, schema-versioned JSON (`--json`). `lava check` is the one-command verification loop.
3. **Generated project map** — `lava map` compiles the running app into `AGENTS.md` (routes, services, features, env vars, schema). A build artifact, like a lockfile: always current, never hand-edited.
4. **Errors as a feedback loop** — every framework failure is a `LavaProblem` carrying *what* failed, *where* (file:line), the *failing input*, and an *imperative fix*. One error message = one self-correction round trip.

LavaPHP is a small core plus hard-decoupled packs (`lava/db`, `lava/validate`, `lava/view`, `lava/http-client`), each a separate Composer package depending only on core. A deterministic feature-flag system gates every pack at boot and app features per request — and the app *tells you* when a pack is missing, with the exact command to install it.

## Repository layout

```
packages/core        lava/core        — the kernel: boot, DI, routing, feature flags, console, errors
packages/db          lava/db          — query builder, schema DSL, migrations (PDO)
packages/validate    lava/validate    — typed validation DSL
packages/view        lava/view        — Twig integration
packages/http-client lava/http-client — small HTTP client (ext-curl)
packages/app         lava/app         — application skeleton (composer create-project target)
apps/demo            — dogfood demo app, the canonical example
docs/                — conventions, per-pack docs, stable JSON schemas for every CLI command
```

## Development

```
composer install     # installs all packs via path repositories
composer verify      # phpunit (all package suites) + phpstan
```

Status: pre-release (0.x under active development). PHP `^8.3`.

## License

MIT — see [LICENSE](LICENSE).