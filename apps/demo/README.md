# lava/demo

The framework's **canonical app**: a small tasks service that uses every pillar
and every shipped pack, and the app the framework's own acceptance runs are
executed against.

It exists to be read. When you want to know what a LavaPHP app looks like — how a
route is declared, how a service is wired, how a pack is gated, how a validation
failure reaches a client, how a migration is written, how a template renders —
this is the answer, and everything it does is meant to be copied.

It is also a *test fixture*: it lives in the monorepo so that a change to core or
to any pack that breaks a real app breaks this one, in CI, on the same commit.

## Run it

```sh
composer install
vendor/bin/lava db:migrate      # creates var/demo.sqlite and the tasks table
vendor/bin/lava app:seed        # four sample tasks
vendor/bin/lava serve           # http://127.0.0.1:8080
```

Then open <http://127.0.0.1:8080/> for the HTML board, and
<http://127.0.0.1:8080/upstream/health> for the one route that leaves the
process.

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
| `GET` | `/` | the task board, as HTML |
| `GET` | `/tasks/{id}/view` | one task, as HTML |
| `GET` | `/upstream/health` | the upstream's health, fetched with `lava/http-client` |

`GET /tasks/export` is gated so the demo has a flag that does something visible.
It is `on` in `config/features.php`; set `LAVA_FEATURE_TASKS_CSV_EXPORT=off` and
the route becomes a real 404 — `lava routes` stops listing it,
`lava features resolve tasks_csv_export` shows the three-layer trace that decided
that, and the board's export link disappears with it. The route and the link ask
the same resolver, so the page can never advertise a page the router would 404.

Errors are worth reading here, because they are the pillar. A `POST /tasks` with
two bad fields answers **422** with *both* problems in one response — each
carrying the field, the rule, the value that was sent, and the fix to apply. It
never fails one field at a time.

```sh
curl -s localhost:8080/tasks -H 'content-type: application/json' \
  -d '{"title":"","due_on":"next tuesday"}' | python3 -m json.tool
```

## The HTML surface

`GET /` is a page, not a negotiated view of `/tasks`. There is no content
negotiation in this framework: a route answers one media, and a caller who wants
the other asks for a different route. So `app/Http/TasksController.php` is the
API and `app/Http/TasksPageController.php` is the page, and neither has to ask
what the caller would have preferred.

The templates are in `views/`, and they are where two things `lava/view` exists
for are visible:

```twig
<a href="{{ url('tasks.page', {id: task.id}) }}">{{ task.title }}</a>
{% if feature('tasks_csv_export') %}<a href="{{ url('tasks.export') }}">CSV</a>{% endif %}
```

- `url()` takes a route **name**, so renaming a route's path cannot leave a
  broken link behind — an unknown name is an `unknown_route` problem naming the
  route, at render time.
- `feature()` answers whether a flag is on, from the same resolver the router
  uses to decide whether a route exists at all.
- `{{ task.title }}` is escaped. Escaping is not configurable, and does not
  depend on the filename: the dangerous spelling is the one you have to type,
  `{{ task.title|raw }}`. Try it — `POST` a title of `<script>alert(1)</script>`
  and the board shows the text.

A missing task is the app's own `task_not_found`, not a page-shaped 404 of its
own. One failure is one code with one fix, whatever representation was asked for:

```sh
curl -s localhost:8080/tasks/999/view | python3 -m json.tool          # JSON
curl -s -H 'Accept: text/html' localhost:8080/tasks/999/view | head   # the diagnostics page
```

## The outbound call

`GET /upstream/health` fetches a URL through `lava/http-client` and returns what
it says. It is the demo's only route that leaves the process, and the only one
whose failure is raised by a pack rather than constructed by this app — so
`UpstreamController` is one statement with no error branch, and a refused
connection still arrives as `transport_failed` at 502, in the same envelope a
rejected request or a broken boot uses.

What it fetches is `app.upstream` in `config/app.php`, which defaults to this
app's own `/health` — so `lava serve` in one terminal is the whole setup, and the
demo never depends on the internet. `UPSTREAM_URL` wins over that default:

```sh
UPSTREAM_URL=https://api.example.com lava serve
curl -s localhost:8080/upstream/health
```

Point it at nothing and the failure is the point:

```sh
UPSTREAM_URL=http://127.0.0.1:9 lava serve
curl -s localhost:8080/upstream/health | python3 -m json.tool
```

`config/http_client.php` sets the timeout, the retry ceiling and the user agent.
The retry rule is the pack's, not this file's: `retries` applies to `GET`,
`HEAD`, `PUT`, `DELETE`, `OPTIONS` and `TRACE` and to nothing else, so a POST
sent twice is never two creates however the file is written.

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
| Four packs, one app, no coupling between them | packs | `TaskRepository` uses `lava/db`; `TasksController` uses `lava/validate`; `TasksPageController` uses `lava/view`; `Upstream` uses `lava/http-client` |
| All validation problems at once | errors as a loop | `TasksController::store` |
| An app-owned problem code with a fix | errors as a loop | `app/Problem/TaskNotFound.php` |
| A generated map that cannot disagree with `lava routes` | project map | `AGENTS.md` |
| Middleware resolved from the container | zero magic | `app/Middleware.php` + the `RequestIdMiddleware` registration |
| Migrations as files with an `up` and a `down` | db | `app/Database/Migrations/` |
| A page and an API over the same data, explicitly | zero magic | `TasksPageController` vs `TasksController` |
| An app service composing a pack service | packs | `App\Upstream\Upstream` in `app/Services.php` |

## The tests

```sh
vendor/bin/lava test          # what `lava check` runs
vendor/bin/phpunit            # the same suite, directly
```

`tests/TasksTest.php` and `tests/PagesTest.php` boot the app **in-process** — no
server, no network, no fixture database — by pointing `DATABASE_DSN` at
`sqlite::memory:` and running the migrations in `setUpBeforeClass`. Every request
goes through the same handler `public/index.php` uses, so the suite tests the
real dispatch path rather than a parallel one.

`tests/UpstreamTest.php` is the exception, and has to be: the route it covers
leaves the process, and `TestClient` never touches a socket. So it starts a real
`php -S` from `tests/Support/UpstreamServer.php` with
`tests/fixtures/upstream/router.php` behind it, and boots the app with
`UPSTREAM_URL` pointed at it. One route per thing the client has to get right,
including `/flaky-<id>`, which drops the connection on the first request so the
retry rule can be counted from the server's own side of the socket.

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
- **`/upstream/health` fetches a URL from config, never from the request.** An
  endpoint that fetched a caller-supplied URL is an SSRF primitive — the reason
  `lava/http-client` refuses `file://` and `gopher://` at all — and a demo that
  shipped one would be teaching the wrong thing.
- **`config/app.php` reads `UPSTREAM_URL` itself.** The demo's suite has to be
  able to point the fetch at a server it starts, and a config file reading one
  environment variable is the documented way to let the environment win. It is
  also what keeps the whole precedence rule in one file instead of split between
  a config reader and a service factory.

## Requirements

`lava/db` needs a PDO driver, and `lava/http-client` needs `ext-curl`. The demo's
default DSN is SQLite, so `pdo_sqlite` has to be installed
(`sudo apt install php8.5-sqlite3`, or the equivalent). CI's `ubuntu-latest` +
`setup-php` image ships both.

