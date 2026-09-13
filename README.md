# LavaPHP

An open-source PHP framework designed for **agent-first usage**: an AI agent (or a human who likes explicit code) can read the entire core, verify its work in one command, understand any app from one generated file, and self-correct from a single error message.

## The four pillars

1. **Conventions & zero magic** — one canonical way to do each thing. No facades, no auto-wiring, no generated proxies, no container magic. Every wire is visible in `app/Services.php`, `app/Routes.php`, `app/Modules.php`, `config/*.php`.
2. **Agent-first CLI** — every `lava` command emits stable, schema-versioned JSON (`--json`). `lava check` is the one-command verification loop.
3. **Generated project map** — `lava map` compiles the running app into `AGENTS.md` (routes, services, features, env vars, schema). A build artifact, like a lockfile: always current, never hand-edited.
4. **Errors as a feedback loop** — every framework failure is a `LavaProblem` carrying *what* failed, *where* (file:line), the *failing input*, and an *imperative fix*. One error message = one self-correction round trip.

LavaPHP is a small core plus hard-decoupled packs (`lavaphp/db`, `lavaphp/validate`, `lavaphp/view`, `lavaphp/http-client`), each a separate Composer package depending only on core. A deterministic feature-flag system gates every pack at boot and app features per request — and the app *tells you* when a pack is missing, with the exact command to install it.

## Repository layout

```
packages/core        lavaphp/core        — the kernel: boot, DI, routing, feature flags, console, errors
packages/db          lavaphp/db          — query builder, schema DSL, migrations (PDO)
packages/validate    lavaphp/validate    — typed validation DSL
packages/view        lavaphp/view        — Twig integration
packages/http-client lavaphp/http-client — small HTTP client (ext-curl)
packages/app         lavaphp/app         — application skeleton (composer create-project target)
apps/demo            — dogfood demo app, the canonical example
apps/blog            — a blog with sign-in, built the way a consumer builds one
docs/                — conventions, per-pack docs, sessions and CSRF, stable JSON schemas for every CLI command
```

## Installing

An app starts from the skeleton on Packagist:

```sh
composer create-project lavaphp/app my-app
cd my-app
./vendor/bin/lava check
```

and a pack is one `composer require` away: `lavaphp/db`, `lavaphp/validate`,
`lavaphp/view`, `lavaphp/http-client`. Each package is published from a
read-only mirror of its directory, because Packagist reads `composer.json` only
at a repository root — [docs/releasing.md](docs/releasing.md#publishing) has how.

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