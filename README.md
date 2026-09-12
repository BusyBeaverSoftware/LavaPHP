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

## Installing

There is no Packagist release yet, so clone the repository:

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

The per-package commands this will eventually support — `composer require
lava/db`, `composer create-project lava/app my-app` — **do not work yet.** None
of the six packages is on Packagist, and each needs a **split mirror** before it
can be: Packagist reads `composer.json` only at a repository root, and this
repository's root is the monorepo. [docs/releasing.md](docs/releasing.md#the-tag)
records exactly where that stands and what it would take.

## Development

```
composer install     # installs all packs via path repositories
composer verify      # phpunit (all suites) + phpstan level 8 + lava/core at max
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