# LavaPHP problem codes

Every `LavaProblem` carries a stable snake_case `code` — the JSON discriminator
an agent can match on. Codes are a public contract from 0.1.0 onward: a code is
never renamed or repurposed; new problems get new codes. Every code maps 1:1 to
one Problem class and is exercised by a fixture test — core codes in
`Lava\Core\Problem\`, a pack's codes in `Lava\<Pack>\Problem\`, because the pack
owns the knowledge that makes the fix text worth reading and a core class cannot
name a pack's own DSN variable, dialect list, or migration directory.

The namespace is the one thing that varies; the *shape* is not. A pack's problem
is a `LavaProblem` like any other, so it flows through the same report, the same
CLI envelope, and the same HTTP error page with no adapter in between. That is
the point of the pack depending on core rather than the reverse.

Problem JSON shape (identical in boot reports, `lava check`, HTTP error pages,
and every `--json` command):

```json
{"code":"missing_pack","problem":"…what failed, one sentence…","fix":"…imperative fix…",
 "context":{…failing input…},"source":{"file":"app/Modules.php","line":4},"severity":"fatal"}
```

`source.file` is an absolute path on the wire — the shape above is elided for
readability; see [conventions.md](conventions.md#the-cli-contract).

## Registry

| Code | Class | Thrown when | Fix pattern |
|---|---|---|---|
| `invalid_config` | `InvalidConfig` | a config file returns a non-array, an unknown key/section appears, or a typed accessor finds a wrong type | name the file, the expected shape |
| `invalid_env_file` | `InvalidEnvFile` | `config/.env` has a malformed line | quote the line and line number |
| `duplicate_service` | `DuplicateService` | a container id is registered twice | show both registration sites |
| `service_not_registered` | `ServiceNotRegistered` | code resolves an id that was never registered | show the exact registration line to add |
| `circular_service` | `CircularService` | a service factory (transitively) resolves itself | print the chain |
| `unknown_feature` | `UnknownFeature` | a flag name was never defined anywhere | suggest the nearest defined name |
| `duplicate_feature` | `DuplicateFeature` | a flag name is defined twice | show both definition sites |
| `unknown_env_branch` | `UnknownEnvBranch` | a per-env flag has no branch for the current env | name the missing branch |
| `invalid_flag_value` | `InvalidFlagValue` | a Flag was constructed or parsed with an invalid value (percentage out of range, unparsable env syntax, nested per-env) | quote the value and the grammar |
| `invalid_feature_name` | `InvalidFeatureName` | a flag name doesn't match `[a-z][a-z0-9_]*` | quote the name and the rule |
| `missing_pack` | `MissingPack` | a module is listed in `app/Modules.php`, its feature resolves on, but the pack's module class doesn't exist | the exact `composer require lava/<pack>` command |
| `module_mismatch` | `ModuleMismatch` | a loaded module's `PackInfo` disagrees with its `app/Modules.php` entry (package or feature) | show both values and the entry's line |
| `invalid_gating` | `InvalidGating` | an audience-targeted flag (rollout/users) gates a boot-lifetime resource (a module) | point at per-request gating (`->when()`) or a deterministic flag |
| `unexpected_failure` | `UnexpectedFailure` | a non-LavaProblem throwable escaped during boot (e.g. a PHP error in a user config file) | wrap the original message + step |
| `bad_route_pattern` | `BadRoutePattern` | a route path is malformed (no leading `/`, untyped `{param}`, unknown/duplicate param type, unclosed placeholder) or URL generation passed a value the route would never match | quote the path and the exact syntax to write |
| `bad_handler` | `BadHandler` | a route handler breaks the handler contract: missing/malformed spec, class not instantiable, constructor takes arguments, method missing/non-public, no or wrong return type, non-injectable parameter | name the handler and the exact signature to write |
| `bad_middleware` | `BadMiddleware` | a middleware class-string doesn't exist or doesn't implement PSR-15 `MiddlewareInterface` | name the class and the PSR-15 signature |
| `duplicate_route_name` | `DuplicateRouteName` | two routes share a name | show both registration sites |
| `route_not_found` | `RouteNotFound` | no route matched the method + path (also the real 404 for a gated route whose flag is off) | `Run: lava routes --json` |
| `method_not_allowed` | `MethodNotAllowed` | the path matched but the method didn't (also: HEAD to a GET-only route — HEAD is never auto-mapped) | list the accepted methods |
| `unknown_route` | `UnknownRoute` | URL generation asked for a route name that isn't registered | suggest the nearest registered name |
| `malformed_body` | `MalformedBody` | a request declared a JSON content type and the body is not valid JSON, or is valid JSON that is not an object | send valid JSON, or send it as a form instead |
| `unknown_command` | `UnknownCommand` | `lava <name>` named a command this app doesn't have | suggest the nearest registered command, else `lava list` |
| `duplicate_command` | `DuplicateCommand` | two commands claim the same name (core, a pack, or `app/Commands.php`) | rename or remove the second; when both claim one pack, override `pack()` |
| `missing_entry_point` | `MissingEntryPoint` | `lava serve` found no `public/index.php` to run — checked before the server is announced | copy the canonical one from the `lava/app` skeleton |
| `missing_test_runner` | `MissingTestRunner` | the app has no `vendor/bin/phpunit` to run its suite with | `Run: composer install --dev`, or set `LAVA_PHPUNIT` |
| `bad_test_report` | `BadTestReport` | the runner ran but wrote no JUnit report, or wrote one that isn't well-formed XML | run the runner directly; its own output rides in `context` |
| `not_an_app` | `NotAnApp` | the directory booted has no `app/`, no `config/`, and no `public/index.php` | name the directory and the layout to create |
| `bad_usage` | `BadUsage` | a command was invoked with a missing or malformed argument (the command exists; the arguments don't) — exit 2 | quote the usage line |
| `missing_env_var` | `MissingEnvVar` | a declared required env var has no value in the process environment or `config/.env` — **severity warn** | set it in `config/.env` or export it |
| `unknown_selector` | `UnknownSelector` | `lava describe <selector>` matched no route, service, flag, env var, or command | suggest the nearest name and list every candidate namespace |
| `stale_map` | `StaleMap` | the committed `AGENTS.md` is not an accurate map of the app — absent (`why: missing`), generated from an older app (`why: stale`), or unwritable when `lava map` tried to write it (`why: unwritable`) — **severity warn** | `Run: lava map` |
| `unsupported_dialect` | `UnsupportedDialect` (db) | `DATABASE_DSN` names a scheme no driver handles (`pgsql`, `mysql`, `sqlite` are supported) | list the supported schemes and show a working DSN |
| `db_not_configured` | `DbNotConfigured` (db) | a `db:*` command ran with no `DATABASE_DSN` in the environment or `config/database.php` | show the exact line to add |
| `db_connection_failed` | `DbConnectionFailed` (db) | the DSN is well-formed but the driver refused it — bad credentials, missing database, server down. The password in the DSN is redacted | report the driver's own message; name the server and database |
| `bad_query` | `BadQuery` (db) | a query was built with something the builder cannot express — `IS NULL` as an equality, an array or object bound as a value, an empty `IN`, an empty where fragment, a read verb in a write statement, a negative limit | name the column and the builder call to use instead |
| `bad_schema` | `BadSchema` (db) | a table definition is invalid — empty or duplicate column, `autoIncrement` on a non-integer or on a composite key, an index on a column that isn't declared, a non-literal default, an inline FK on MySQL | name the column/index and the declaration to write |
| `query_failed` | `QueryFailed` (db) | the driver rejected a statement the builder produced — the DDL or SQL is well-formed but the database disagrees (a type it won't accept, a constraint it can't satisfy) | the statement, the bound values, and the driver's message |
| `migration_failed` | `MigrationFailed` (db) | a migration's `up()`/`down()` threw, or a recorded migration's file is gone | point at the migration file and line, and say the batch was NOT rolled back |
| `invalid_migration_file` | `InvalidMigrationFile` (db) | a file in `app/Database/Migrations/` is unusable — a name that isn't `<timestamp>_<snake>`, a `return` that isn't a `Migration`, a file that throws while loading, or a `db:new` target that exists or can't be written | quote the file and the naming rule |
| `validation_failed` | `ValidationFailed` (validate) | a declared field's value fails a rule — **422**, the only problem that is the *caller's* fault | name the field, the rule, what it wanted, and redact the value if the field looks secret |
| `invalid_rule` | `InvalidRule` (validate) | a rule cannot do its job: an unparsable or undelimited pattern, an empty allowed set, a negative length, a bound with no type rule to decide from, a bound on a boolean, or a `->custom()` predicate that threw | name the declaration to rewrite, at the line that declares it |
| `unreadable_field` | `UnreadableField` (validate) | the handler read a field that has no readable value — absent (optional, so validation let it through missing) or not coercible to the type asked for | `->required()` on the field, a `->has()` check, or the type rule that matches how it is read |
| `template_not_found` | `TemplateNotFound` (view) | a template was rendered that is not in the template directory | list what is there, extension included, and name the exact file to create |
| `template_failed` | `TemplateFailed` (view) | a template does not compile, or threw while rendering — most often a context variable the handler never passed, because `strict_variables` is on | the file and line Twig already computed; name the *handler* when the fault is a missing variable |
| `view_dir_missing` | `ViewDirMissing` (view) | the `views` feature is on and `view.path` is not a directory — raised at **boot** | the path to create, or the config key to change |
| `bad_view_call` | `BadViewCall` (view) | a template called `url()` or `feature()` with an argument those functions cannot use | the template's own fix — never the value that was passed |

`not_an_app` is the one code that exists because booting the wrong directory
**succeeds**. Every user-authored artifact is optional (see
[conventions.md](conventions.md)), so without this check a random directory
yields an app with no routes and `lava routes` there answers
`status: ok, routes: []` — which reads as "your app has no routes" rather than
"there is no app here".

`missing_env_var` is deliberately Warn, not Fatal: `lava env` is a report, and a
diagnostic that itself exits non-zero is an obstacle. `lava check --strict` is
where an unset required var becomes a build failure.

`bad_usage` is the only code that exits **2** rather than 1, alongside
`unknown_command`. An agent can tell "you typed it wrong" from "it ran and
failed" without parsing the body.

The db pack's eight codes split along one line: **whether the answer is knowable
without a database.** `bad_query` and `bad_schema` are raised by the builder and
the schema compiler, both of which are pure — they are thrown on a laptop with no
driver installed, before a connection is opened, and they name the exact
declaration to rewrite. `query_failed` is the other half: the SQL was
well-formed, the driver ran it, and the database disagreed. Those are different
fixes (rewrite the call vs. reconcile with the database), so they are different
codes, and the pair is why the compiler is testable driver-free.

`db_not_configured` and `db_connection_failed` are split for the same reason,
even though both end in "there is no database". The first has a fix the
framework can print verbatim — add `DATABASE_DSN` — and fires before any driver
is touched; the second carries the driver's own message and needs a human to read
it. Folding them into one code would force every consumer to parse `context` to
tell a missing line from a wrong password. `DbConnectionFailed::redact()` strips
the password from the DSN before it reaches `context`, because a problem report
is written to logs and to `--json` output that gets pasted into issues.

`migration_failed`'s fix text is worth reading once, because it was once wrong:
it claimed the batch had been rolled back. It has not, and cannot be — MySQL
commits implicitly on DDL, so a "rollback" there would undo the repository rows
while leaving the tables. Each migration is recorded the moment it succeeds
instead, so a failed run leaves everything before it applied *and* recorded, and
saying so is what makes the run resumable rather than mysterious.

M1/M2/M3 status: every code above has its class; all except `unexpected_failure` are
exercised by fixture/unit tests under `packages/core/tests/` (see
`tests/Unit/KernelBootTest.php` for the fixture-level ones — `bad-routes-app`
collects five route problems in one boot; `module-app` exercises the module
contract end to end).

M4 status: all of M4's codes are live and covered — `unknown_command`
(`tests/Unit/ConsoleTest.php`, `tests/Unit/ConsoleDispatchTest.php`),
`bad_usage` (`tests/Unit/InspectionCommandsTest.php`,
`tests/Unit/ServeCommandTest.php`), `duplicate_command`
(`tests/Unit/RegisterCommandsTest.php`), `missing_env_var` and
`unknown_selector` (`tests/Unit/InspectionCommandsTest.php`),
`missing_entry_point` (`tests/Unit/ServeCommandTest.php`),
`missing_test_runner` and `bad_test_report` (`tests/Unit/TestCommandTest.php`,
`tests/Unit/JunitTest.php`), and `not_an_app`
(`tests/Unit/KernelBootTest.php`). `missing_env_var` is raised by one shared
rule (`Config/EnvAudit`) used by both `lava env` and `lava check`, so the two
commands cannot disagree about whether a variable is set. The table is complete
when 0.1.0 is tagged. `lava check` renders problems fix-first (runnable commands
first).

M5 status: all eight db codes are live, and the pack's own classes live in
`Lava\Db\Problem\`. `unsupported_dialect`, `db_not_configured`,
`db_connection_failed`, `bad_query`, `bad_schema`, `query_failed` and
`invalid_migration_file` are covered by unit tests under `packages/db/tests/`;
`migration_failed` is exercised by the CLI golden tests
(`packages/db/tests/Cli/DbCommandsTest.php`), which run the real binary against a
real SQLite file — including the partial-progress case that the envelope once
reported as `applied: []`. The driver-free half of the pack means the compiler
and builder codes are tested without a database at all, so a machine with no PDO
driver still exercises six of the eight.

The M4 paragraph above used to say "Still to come with their milestones:
pack-specific codes (M5+)". They are here; the next pack's codes go in the same
table with the pack's name in the Class column, which is the whole of the
convention.

`missing_test_runner` and `bad_test_report` are separated on purpose, because
they need different fixes: no runner means the app was never installed, while a
runner that wrote nothing means PHPUnit ran and stopped before testing — a
broken `phpunit.xml`, a bootstrap that fatals. Neither is ever a *framework*
problem, so a red suite contributes no problems at all: `lava test` and
`lava check` let the exit code and `status` go red and leave `problems[]` empty.
The framework has no business pronouncing on code it never read.

M6 status: `malformed_body` and the validate pack's three codes are live.
`malformed_body` is core because the parsing rule is core's — a request that
declares a JSON body and is not one is refused in `RequestBody` before routing,
and the alternative (leaving the parsed body null) would present a syntax error
as every field being missing. The validate codes are in `Lava\Validate\Problem\`,
covered by `packages/validate/tests/`, and the fixture app's three routes
exercise all of them over real HTTP.

`validation_failed` is the one code that made `LavaProblem::httpStatus()`
necessary. A 422 has to be able to come from a pack — the knowledge that makes
the fix text worth reading (which field, which rule, what it wanted) lives in the
validate pack and nowhere else — and the alternative is a `match` on `code()`
somewhere in the HTTP layer, which would make core enumerate the codes of packs
it has never heard of. So a problem declares its own status, the default stays
500 because most problems *are* developer faults, and the three caller-fault
codes override it: `route_not_found` 404, `method_not_allowed` 405,
`malformed_body` 400, `validation_failed` 422.

The validate pack's three codes split on **who made the mistake**, which is also
the split between 422 and 500. `validation_failed` is the caller's value and the
caller's fix. `invalid_rule` is the app's declaration — a pattern that does not
compile, a predicate that throws — so it is a 500 with a file and line in the
app's own source, and it is raised at the declaration rather than on whichever
request reaches the field first. `unreadable_field` is also a 500, but the
mistake is in how the *handler* reads the result: it asked for a field that
validation let through missing, or for a type the field never promised. Folding
`unreadable_field` into `validation_failed` would be the worst of the three
outcomes — it would tell a caller their request was bad when the request was
fine and the accessor was wrong.

M7 status: `stale_map` is live, raised by `Lava\Core\Problem\StaleMap` and
covered by `packages/core/tests/Cli/MapCommandTest.php` and
`packages/core/tests/Unit/ProjectMapTest.php`.

One code carries three `why` values rather than three codes carrying one each,
and the reason is the same one that keeps `missing_test_runner` and
`bad_test_report` apart: whether a consumer would act differently. It would not.
"Your map is not accurate" is the whole message, the fix is `Run: lava map` in
all three cases, and a caller branching on `why` would be branching on a
diagnostic detail. `unwritable` rides here rather than on a code of its own
because the registry is a public contract: it is not worth growing by one for an
environment condition — a read-only checkout — that means exactly what the code
already says.

`stale_map` is Warn, not Fatal, and that is what made `lava check`'s ordering
rule wrong the first time. The rule was "problems whose fix is a runnable
command come first", on the theory that such a fix needs no judgement; but
`stale_map`'s fix IS a runnable command, so a stale comment would have been
hoisted above "your route does not compile". Severity is now the major key and
runnable-ness the minor one — fatal-runnable, fatal, warn-runnable, warn — which
keeps the original intuition inside a severity instead of across one.

The same code is a *warning* in `lava check` and a *failure* in
`lava map --check`. That is not an inconsistency: `check` reports the app's
health, and a documentation lag is a warning on it; `map --check` is asked one
question and answers it, and a verification command that answers "no" with exit
0 is useless to a build. `lava check --strict` is where the warning becomes a
build failure, which is the same door `missing_env_var` uses.

M8 status: the view pack's four codes are live in `Lava\View\Problem\`, covered
by `packages/view/tests/` — the three render-time ones over real HTTP through
the fixture app (`tests/Http/ViewOverHttpTest.php`), the boot-time one and the
config branches in `tests/Unit/ViewModuleTest.php`.

`template_failed` is one code with two factories, `syntax()` and `runtime()`, and
the split is the fix rather than the diagnosis. A template that does not compile
is a typo in the template text; a template that threw while rendering is, with
`strict_variables` on, almost always a variable the *handler* never passed into
the context. Those send the reader to different files, so they get different fix
text — but they get one code, because a consumer of the registry acts on both
identically: read the fix, open `source.file:line`, edit.

`bad_view_call` exists because the alternative is a PHP `TypeError` raised from
inside Twig's compiled template in `var/views/`, naming a class the template
author never typed and a line in a file that no longer resembles what they wrote.
Four factories — a non-string route name, params that are not a map, a param
value that cannot be in a URL, a non-string flag name — share one code for the
same reason `stale_map`'s three `why` values do: a reader does the same thing
with each. The messages name the *type* of what was passed and never its value,
because a problem report is an error page and a log line and a route param can
hold anything the app put in its model.

The renderer unwraps a `LavaProblem` that a template function raised, so
`bad_view_call` reaches the caller as itself rather than as the `template_failed`
Twig would otherwise wrap it in. That is the same rule `migration_failed`
follows — a wrapper problem wraps only throwables that are not already
`LavaProblem`s — and it is what keeps the specific code, and the specific fix,
from being buried inside Twig's own sentence.

`view_dir_missing` is raised at boot, alone among this pack's codes, and the
difference is what the reader can do about it. A missing *template* is the normal
state of an app being built — `lava check`, `lava routes` and `lava serve` all
have to work on it — so that one waits for the request. A missing template
*directory* makes every render fail identically, so N request-time errors
collapse into one boot message naming the path and the config key.

## App-owned codes are not in this registry

Every code in the table above is one the *framework* raises, and this document is
its catalogue. An app raises codes too — the demo's `task_not_found` is one — and
they belong in the app's own `Lava\Core\Problem\LavaProblem` subclass, not here.

The split is the same one that puts a pack's problems in `Lava\<Pack>\Problem\`
rather than in core: whoever owns the knowledge writes the message and the fix.
Core cannot write "Run: `lava app:stats --json`, or GET /tasks, to list the ids
that exist" — that sentence is only true in one app. A registry entry here would
be a framework document claiming to know something it does not.

What an app-owned code does inherit is the *shape*, and that is the whole point:
subclass `LavaProblem`, return a snake_case `code()`, pass a fix string, and the
problem flows through the same `ProblemReport`, the same CLI envelope, and the
same HTTP negotiation as `missing_pack` — with no registration step and nothing
in core to change. `lava check` does not lint the code's spelling, because it
cannot know what the app considers stable; the code becomes a contract the
moment the app publishes it, and that is the app's call to make, not the
framework's.

So: absent from this table is not an oversight, and an app adding a row here
would be a bug. If a code you are looking at is not listed, grep the app's
`Problem/` directory — that is where it lives.
