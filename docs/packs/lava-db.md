# lavaphp/db

Typed query builder, schema DSL, and migrations on PDO. Three database
dialects — SQLite, MySQL, Postgres — through one API, with the differences
that matter (DDL syntax, boolean literals, FK clauses) handled by a compiler
rather than by you.

Values are **always** parameter-bound: no method interpolates a *value* into
SQL, and the raw escape hatches (`whereRaw()`, `defaultExpression()`) take SQL
you wrote yourself with the bindings as a separate argument — so an injection is
a thing you would have to construct deliberately.

Codes this pack raises: `unsupported_dialect`, `db_not_configured`,
`db_connection_failed`, `bad_query`, `bad_schema`, `query_failed`,
`migration_failed`, `invalid_migration_file` — see
[problem-codes.md](../problem-codes.md).

## Install and enable

```sh
composer require lavaphp/db
```

```php
// app/Modules.php
use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\Db\DbModule::class, package: 'lavaphp/db', feature: 'db'),
];
```

The pack is enabled by that entry and by nothing else — there is no service
provider registry to remember. It registers exactly one service,
`Lava\Db\Connection::class`, and four commands. The connection is a singleton
built at boot from settings read at boot, **without connecting**: its
constructor stores a DSN and nothing touches a driver until the first query.
So the pack can be enabled on an app whose database does not exist yet, and
`lava db:status` there reports `db_not_configured` — a diagnosis — instead of
failing to boot.

## Configure

Three settings, each read from the real environment first and
`config/database.php` second:

| Env var | Config key | Meaning |
|---|---|---|
| `DATABASE_DSN` | `database.dsn` | `sqlite:/path/to/app.sqlite`, `mysql:host=…;dbname=…`, `pgsql:host=…;dbname=…` |
| `DATABASE_USER` | `database.user` | ignored by SQLite |
| `DATABASE_PASSWORD` | `database.password` | ignored by SQLite; redacted from every problem report |

The environment wins because the environment is what a deploy changes. An
**empty string counts as unset in both places**: `DATABASE_DSN=` is how a
deploy template spells "leave this blank", and treating it as a DSN would
produce "the scheme `''` is not supported" — a confident diagnosis of the
wrong problem.

There is no default DSN. A relative SQLite path would depend on the working
directory and an absolute one would be machine-specific, so a missing DSN is
reported rather than guessed.

`config/database.php` is read by core's boot, not by the pack: `DbModule`
declares `configFiles: ['database']` in its `PackInfo`, and core's
`LoadPackConfig` step loads what a pack declares. That is why `lava config`
shows `database.dsn` alongside `app.env`, and why a pack adds no loader of its
own.

## Commands

All four take `--env=<name>` (boot under another environment) and `--json`.
Every `--json` envelope obeys a schema in
[`docs/schemas/`](../schemas/) — note the name: `db:status`'s contract is
`lava.db.status/1`, because a colon is illegal in a Windows path and the
schema name is also a file name.

### `lava db:status`

What the repository knows: applied migrations (ordered by batch, then name —
oldest first), pending ones, and orphans — recorded migrations whose file is
gone, which is what a bad merge or a deleted branch leaves behind. Orphans are
reported, not ignored: a pending migration that will run against a database
already carrying its effects is worth knowing about before you run it.

```json
{"applied":[{"name":"2026_01_01_000000_create_users_table","batch":1,
             "applied_at":"2026-09-11 11:36:15"}],
 "pending":["2026_01_01_000001_create_posts_table"],
 "orphaned":[],"batch":1,"pending_count":1}
```

### `lava db:migrate`

Applies every pending migration in filename order, in **one batch** — the batch
number is what `db:rollback` counts, so "undo the last deploy" does not require
knowing how many migrations that deploy contained.

A run that fails part-way reports the migrations that did apply:

```json
{"applied":["2026_01_01_000000_create_users_table"],"applied_count":1,"batch":1}
```

That is deliberate and it is the payload's whole point. Each migration is
recorded the moment it succeeds, so a failure leaves everything before it
applied *and* recorded, and the next `db:migrate` resumes from there.

**There is no transaction around the batch.** It would look tidier and it would
work on SQLite and Postgres, but MySQL commits implicitly on every DDL
statement — so there the "rollback" would undo the repository rows while the
tables stayed, leaving a migration recorded as unapplied that had in fact been
applied, and the next run would fail on `table already exists`.

### `lava db:rollback [--batches=<n>]`

Undoes the most recent `<n>` batches (default 1), newest migration first, and
deletes their repository rows. `--batches` counts *batches*, not migrations,
and is named for what it counts. A non-numeric value is refused rather than
coerced: `--batches=all` becoming 0, or 1, or `PHP_INT_MAX`, would silently
pick a different amount of undo on the one operation where that is hardest to
notice.

```json
{"rolled_back":["2026_01_01_000001_create_posts_table",
                "2026_01_01_000000_create_users_table"],
 "rolled_back_count":2,"batches":[1]}
```

`batches` is derived from what actually came off, not from what was asked for,
so it and `rolled_back` always agree — including on a run that stopped part-way.

### `lava db:new <description>`

Generates a migration file and prints its name, path, and table.

```sh
lava db:new create_users_table     # → 2026_09_11_143022_create_users_table.php
lava db:new CreateUsersTable       # same file: CamelCase is accepted
lava db:new add_avatar_to_users    # a commented template; see below
```

**The command owns the name.** The name *is* the ordering, so
`<YYYY_MM_DD_HHMMSS>_<snake_case>` is generated rather than typed — two people
on separate branches cannot collide on a number they were both told to
increment. Only the `create_<table>_table` shape produces a working body;
every other description gets a commented template, and the payload says so via
`is_empty: true`, because `add_avatar_to_users` could be read as "add a column
to users" and guessing which column, of which type, with which default is how a
generator writes a migration nobody asked for.

**Generation order is run order.** A migration generated in the same second as
the newest one on disk — or while this machine's clock is behind it — takes the
next second instead, so `create_monitors_table` then `create_checks_table` can
never sort `checks` first. That matters beyond SQLite: a foreign key to a table
that does not exist yet is an error on MySQL and PostgreSQL that SQLite never
raises. An existing file is still refused, never overwritten — it may be
someone's half-written work.

## Migrations

Files live in `app/Database/Migrations/`, named
`<YYYY_MM_DD_HHMMSS>_<snake_case>.php`, and **return an instance**:

```php
<?php

declare(strict_types=1);

use Lava\Db\Connection;
use Lava\Db\Migration\Migration;
use Lava\Db\Schema\ForeignAction;
use Lava\Db\Schema\Table;

return new class extends Migration
{
    public function up(Connection $db): void
    {
        $db->schema()->create('posts', function (Table $t): void {
            $t->id();
            $t->bigInt('user_id')->references('users')->onDelete(ForeignAction::Cascade);
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->timestamps();

            $t->index('user_id');
        });
    }

    public function down(Connection $db): void
    {
        $db->schema()->dropIfExists('posts');
    }
};
```

Returning an instance removes the two things that make migration discovery
fragile elsewhere: parsing a class name out of a timestamp, and instantiating a
class by reflection. It also means the file's class name is irrelevant, so
there is nothing to keep in sync with the file name.

Both directions are abstract: a migration that genuinely cannot be undone must
say so by throwing, not by silently doing nothing.

**Order is filename order, and a bad order fails loudly.** The fixture's second
migration references a table the first one creates, so applying them out of
order fails at DDL time and rolling them back in the wrong order fails too.

## Schema DSL

`$db->schema()` gives `create()`, `table()`, `drop()`, `dropIfExists()`,
`has()`, and `tables()`. `has()` then `create()` rather than
`CREATE TABLE IF NOT EXISTS`, because the DSL has no `if not exists` and adding
one to the compiler for a single caller would be a feature the schema layer
does not otherwise offer.

`table()` **adds columns and indexes, and nothing else** — no primary key, no
other constraint changes, and no altering a column that already exists. An
`index()` or `unique()` there may cover a column the table already has, and a
column's own `->unique()` is created too; `primary()` is refused with
`bad_schema`, because SQLite cannot add one without rebuilding the table.
(Before 0.2.1, `table()` dropped every index declaration without a word.)
Changing a column's type
or nullability means creating a new table and copying, which is a migration you
should write deliberately rather than one a DSL should perform silently.

Inside the closure, `Table` declares columns and indexes:

| Columns | Indexes |
|---|---|
| `id()`, `int()`, `bigInt()`, `string($name, $length = 255)`, `text()`, `bool()`, `float()`, `decimal($p, $s)`, `date()`, `dateTime()`, `time()`, `json()`, `uuid()`, `binary()`, `foreignId()`, `timestamps()`, `softDeletes()` | `primary(...$cols)`, `unique($cols, $name = null)`, `index($cols, $name = null)` |

Column modifiers: `nullable()`, `default($value)`, `defaultExpression($sql)`,
`primary()`, `unique()`, `autoIncrement()`, `references($table, $column = 'id')`,
`onDelete()`, `onUpdate()`.

`default()` takes a literal and rejects anything else (`bad_schema`): a default
is DDL, evaluated once when the table is created, so a value that came from a
variable would freeze that variable's value into the schema. Use
`defaultExpression()` when you mean an expression.

### Foreign keys are table-level

A referencing column becomes a table-level
`CONSTRAINT … FOREIGN KEY (…) REFERENCES …` clause, never an inline column
`REFERENCES`. MySQL parses the inline form and then **silently ignores it** — no
error, no constraint. A schema layer whose FK syntax works on two dialects and
quietly does nothing on the third is worse than one that lacks the feature,
because the failure appears months later as orphaned rows.

The consequence: `addColumns()` **refuses** a referencing column on MySQL
rather than emitting DDL that does nothing, since `ALTER TABLE ADD CONSTRAINT`
on an existing table is a different operation this DSL does not offer.

References to a column the target does not have are caught
(`bad_schema`). References to a table that does not exist are **not**: the
target may be created by a later migration, and catching that would make a
legitimate forward reference impossible. The asymmetry is the point — it catches
the mistake that is always a mistake and leaves the case that is sometimes
correct to the database.

### Dialect differences the compiler handles

- Boolean literals: `TRUE`/`FALSE` on Postgres, `1`/`0` on MySQL and SQLite.
- Auto-increment: `INTEGER PRIMARY KEY AUTOINCREMENT` on SQLite,
  `<type> NOT NULL AUTO_INCREMENT PRIMARY KEY` on MySQL, `SERIAL`/`BIGSERIAL
  PRIMARY KEY` on Postgres.
- Identifier quoting: `"x"` on Postgres and SQLite, `` `x` `` on MySQL, with
  the quote character doubled inside an identifier.
- Types: `JSON`/`JSONB`, `BLOB`/`BYTEA`, `DATETIME`/`TIMESTAMP`,
  `TINYINT(1)`/`BOOLEAN`, `NUMERIC`/`DECIMAL`, `CHAR(36)`/`UUID`.

SQLite stores `Date`, `DateTime`, `Time` and `Json` as `TEXT`, and both integer
widths as `INTEGER`. It has no other choice — its type-affinity system has no
`DATE` or `JSON` type — and an `autoIncrement` column must be spelled exactly
`INTEGER` to become a rowid alias, so the requested width cannot be honoured
there. The DSL still means "a datetime" to the caller and the binding layer
still writes the documented format; only the storage class differs.

The compiler is **pure** — it turns a definition into DDL strings and touches
no database. That is what lets its tests assert the exact DDL for all three
dialects with no driver installed, which a live test never could: a live test
can only assert that *something* worked.

## Query builder

```php
$query = $db->table('users')
    ->where('active', Operator::Eq, true)
    ->whereIn('role', ['admin', 'editor'])
    ->orderBy('created_at', Direction::Desc)
    ->limit(20)
    ->toSelect();

$rows = $db->fetch($query);   // list<array<string,mixed>>
```

A query is a value, not a handle: building one touches no database, so a
statement can be built in one place, inspected or asserted in another, and
executed in a third. `Connection` is what executes it — `fetch()`,
`fetchOne()`, `scalar()`, `run()`, `execute()`, `query()` and `statement()` for
raw SQL, `transaction()` for a closure, and `lastInsertId()`.

`table()` gives `select()`, `where()` / `orWhere()`, `whereNull()` /
`whereNotNull()`, `whereIn()` / `whereNotIn()`, `whereBetween()`, `whereRaw()`,
`whereGroup()` (each with an `or` variant), `innerJoin()`, `leftJoin()`,
`orderBy()`, `limit()`, `offset()`, and the terminals `toSelect()`, `insert()`,
`insertMany()`, `update()`, `delete()`.

### Grouping

A flat chain combines left to right, so `where('a')->orWhere('b')->where('c')`
is `a OR (b AND c)` — `AND` binds tighter, which is the opposite of what the
chain looks like as a sentence. `whereGroup()` writes the parentheses:

```php
$db->table('users')
    ->whereGroup(function (ConditionGroup $group): void {
        $group->where('role', Operator::Eq, 'admin')->orWhereIn('plan', ['pro']);
    })
    ->whereNotNull('email_verified_at')
    ->toSelect();
// … WHERE ("role" = ? OR "plan" IN (?, ?)) AND "email_verified_at" IS NOT NULL
```

The closure is handed a `ConditionGroup`, not the builder, because a builder
there would accept `->limit(5)` and the group would drop it — a call that is
accepted and ignored is the one thing this pack refuses everywhere else. The
group is a `Condition` like any other term and the compiler renders it, so its
columns are still quoted and its bindings still ordered by the same code as the
rest of the clause; nesting is just a group inside a group. A closure that adds
nothing is a `bad_query` rather than `()`, which is a syntax error on every
dialect.

`lastInsertId()` returns `?string`, not `string`. PDO returns `string|false`,
and the `false` is a real case — a driver or statement that cannot report it.
Coercing it to `"0"` would be indistinguishable from a real id of zero; `null`
says "not known", which is what a caller needs to branch on.

### The builder refuses what it cannot express

`bad_query` is raised before any connection is opened, for:

- anything but a column name where a column goes — in `select()`, every
  `where*()` column, `orderBy()`, both sides of a join and its table, and the
  keys of an `insert()` or `update()`. A name may be qualified (`posts.title`,
  `main.posts.title`) and may use any letter (`prénom`); `select()` also takes
  `*` and `posts.*`. Everything else — `COUNT(*)`, `LOWER(email)`, `name AS
  author` — would be quoted as one identifier, and SQLite answers an unknown
  quoted identifier with the string itself, so the query would compare or
  return that string instead of failing. Write those with `whereRaw()`,
  `query()` or `statement()`. The check is on the shape of the name, not its
  existence: a typo in a valid-looking name (`where('emial', …)`) still reaches
  SQLite, which reads it as the string `'emial'` when no such column exists;
- a null comparison written as an equality — `where('x', Operator::Eq, null)`,
  because SQL evaluates `x = NULL` as *unknown*, so the condition would match
  nothing and look like an empty table;
- an array bound as a single value (`whereIn()` is the method for a set), or
  anything else that is not a scalar, `null`, a `DateTimeInterface`, or a backed
  enum;
- an empty `IN` — `IN ()` is not valid SQL, and an empty set matches nothing
  either way;
- an operator that is not two-sided (`IsNull`, `In`, `Between`, `Raw`) passed to
  `where()`, which has dedicated methods for each;
- an empty `whereRaw()` fragment, or an empty `whereGroup()` closure, or a write
  with no columns, or a multi-row insert whose rows set different columns;
- a negative `limit()` or `offset()`;
- calling `run()` on a SELECT;
- and **an unbounded `UPDATE` or `DELETE`** — the one that saves a table.

Because these are raised by pure code, they are testable on a machine with no
database at all, and they are why `bad_query` and `query_failed` are separate
codes: one means "rewrite the call", the other means "the database disagreed
with well-formed SQL", and those are different afternoons.

The unbounded-write refusal is worth knowing how to override, since it is the
one that stops work you legitimately meant: add `->whereRaw('1 = 1')` to say
"every row, on purpose", or run the statement directly with
`$db->statement('DELETE FROM users')`.

## Testing this pack

Four layers, and the split is the point — a machine with no PDO driver at all
still runs most of it.

| Layer | Location | Needs |
|---|---|---|
| Compilers and the builder | `packages/db/tests/Sql/`, `…/Query/` | nothing — pure code |
| Live database | `packages/db/tests/Live/` | `DB_TEST_DSN` |
| CLI golden tests | `packages/db/tests/Cli/` | `pdo_sqlite` in this process *and* the subprocess |
| Envelope contracts | `packages/db/tests/Schema/` | nothing, except one test that migrates a real database |

```sh
# From the repo root. Everything that needs no driver runs; the rest skip.
vendor/bin/phpunit --testsuite=db

# The live tests, against SQLite (or point DB_TEST_DSN at MySQL/Postgres).
DB_TEST_DSN=sqlite::memory: vendor/bin/phpunit --testsuite=db

# The CLI golden tests. The extension must be visible to the `lava` subprocess
# too, so it goes in the environment rather than on the command line.
PHP_INI_SCAN_DIR=:/tmp/lava-test-ini vendor/bin/phpunit --testsuite=db
```

The CLI tests skip rather than fail when no driver is available, and the skip
message names `PHP_INI_SCAN_DIR` because that is the difference between "the
parent has the extension" and "the subprocess has it": `-d extension=…` applies
to one process, while the harness forwards the *environment* to the child. The
leading `:` in the value is not a typo — an empty element means "keep the
compile-time default directory", so the scan path is extended rather than
replaced.

The live tests take a DSN from the environment rather than probing for a driver,
because the same tests are meant to run against MySQL and Postgres too; SQLite
is just the one that needs no server.

The CLI tests need a **file** database, not `sqlite::memory:`: each invocation
is its own process, so an in-memory database would be created and discarded by
every command and `db:status` could never see what `db:migrate` did.
