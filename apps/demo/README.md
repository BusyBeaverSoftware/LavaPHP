# lava/demo

The framework's **canonical app**: a small tasks service that uses every pillar
and every shipped pack, and the app the framework's own acceptance runs are
executed against.

It exists to be read. When you want to know what a LavaPHP app looks like — how a
route is declared, how a service is wired, how a pack is gated, how a validation
failure reaches a client, how a migration is written — this is the answer, and
everything it does is meant to be copied.

It is also a *test fixture*: it lives in the monorepo so that a change to core,
`lava/db` or `lava/validate` that breaks a real app breaks this one, in CI, on
the same commit.

## Run it

```sh
composer install
vendor/bin/lava db:migrate      # creates var/demo.sqlite and the tasks table
vendor/bin/lava app:seed        # four sample tasks
vendor/bin/lava serve           # http://127.0.0.1:8080
```

Verify it the way CI does:

```sh
vendor/bin/lava check --strict  # boot, wiring, routes, features, env, commands, the map, the suite
vendor/bin/lava map --check     # is AGENTS.md still an accurate map of this app?
```

## The API

| method | path | what it does |
|---|---|---|
| `GET\|HEAD` | `/health` | liveness; the app's one unauthenticated route |
| `GET` | `/tasks` | list, newest first; `?done=done` or `?done=open` to filter |
| `POST` | `/tasks` | create; `title` required, `due_on` optional |
| `GET` | `/tasks/{id}` | one task, or a `task_not_found` 404 |
| `POST` | `/tasks/{id}/complete` | mark done, idempotently |
| `DELETE` | `/tasks/{id}` | delete |
| `GET` | `/tasks/export` | CSV download — **gated** by `tasks_csv_export` |

`GET /tasks/export` is gated so the demo has a flag that does something visible.
It is `on` in `config/features.php`; set `LAVA_FEATURE_TASKS_CSV_EXPORT=off` and
the route becomes a real 404 — `lava routes` stops listing it, and
`lava features resolve tasks_csv_export` shows the three-layer trace that decided
that.

Errors are worth reading here, because they are the pillar. A `POST /tasks` with
two bad fields answers **422** with *both* problems in one response — each
carrying the field, the rule, the value that was sent, and the fix to apply. It
never fails one field at a time.

```sh
curl -s localhost:8080/tasks -H 'content-type: application/json' \
  -d '{"title":"","due_on":"next tuesday"}' | python3 -m json.tool
```

## The commands it adds

Two app-owned commands, registered in `app/Commands.php`:

```sh
vendor/bin/lava app:stats --json   # {"total":4,"open":4,"done":0,"overdue":0}
vendor/bin/lava app:seed           # insert four sample tasks
```

They exist to prove the extension point: an app adds commands without touching
the framework, and gets `--json` for free because the envelope is the `IO`
contract rather than something each command invents. Neither command branches on
the output mode — the same code path writes a table for a person and an envelope
for an agent.

## What it exercises, and where to look

| feature | pillar it proves | read |
|---|---|---|
| Every route declared, none inferred | zero magic | `app/Routes.php` |
| Every service built by visible code | zero magic | `app/Services.php` |
| A pack gated by a flag, not by a `require` | pack decoupling | `app/Modules.php`, `config/features.php` |
| Two packs, one app, no coupling between them | packs | `app/Tasks/TaskRepository.php` uses `lava/db`; `app/Http/TasksController.php` uses `lava/validate` |
| All validation problems at once | errors as a loop | `TasksController::store` |
| An app-owned problem code with a fix | errors as a loop | `app/Problem/TaskNotFound.php` |
| A generated map that cannot disagree with `lava routes` | project map | `AGENTS.md` |
| Middleware resolved from the container | zero magic | `app/Middleware.php` + the `RequestIdMiddleware` registration |
| Migrations as files with an `up` and a `down` | db | `app/Database/Migrations/` |

## The tests

```sh
vendor/bin/lava test          # what `lava check` runs
vendor/bin/phpunit            # the same suite, directly
```

`tests/TasksTest.php` boots the app **in-process** — no server, no network, no
fixture database — by pointing `DATABASE_DSN` at `sqlite::memory:` and running
the migrations in `setUpBeforeClass`. Every request goes through the same handler
`public/index.php` uses, so the suite tests the real dispatch path rather than a
parallel one.

A few decisions in here are deliberate and documented where they live, because
the alternative would have been a quietly worse demo:

- **`app:stats` counts in PHP.** The query builder has no aggregate verb, and
  `select('COUNT(*)')` would compile to the quoted identifier `"COUNT(*)"` —
  a syntax error. Counting four rows in PHP is the honest demo-scale answer;
  `Connection::query()` is the documented raw path when it stops being one.
- **`app:seed` appends, and has no `--fresh`.** A `--fresh` flag is a one-word
  way to delete a database, and the framework already has `lava db:rollback` +
  `lava db:migrate` for that.
- **The CSV test parses rather than string-matches.** `fputcsv` encloses any
  field containing a space, so the row reads `7,"Ship it",0,…`. That is correct
  CSV; a raw `assertStringContainsString` would be asserting PHP's quoting rules
  instead of the data.
- **`config/database.php` derives an absolute path from `__DIR__`.** A relative
  DSN resolves against the working directory, and a checked-in absolute path is
  wrong on every machine but one.

## Requirements

`lava/db` needs a PDO driver. The demo's default DSN is SQLite, so
`pdo_sqlite` has to be installed (`sudo apt install php8.5-sqlite3`, or the
equivalent). CI's `ubuntu-latest` + `setup-php` image ships it.
