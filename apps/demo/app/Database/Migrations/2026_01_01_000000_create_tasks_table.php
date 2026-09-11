<?php

declare(strict_types=1);

use Lava\Db\Connection;
use Lava\Db\Migration\Migration;
use Lava\Db\Schema\Table;

/**
 * The tasks table.
 *
 * A migration is an anonymous class returned from `require`, in a file named
 * `<timestamp>_<description>.php`. It is not autoloaded and not registered
 * anywhere: the directory IS the registry, ordered by filename, which is why
 * the timestamp is part of the name rather than metadata inside the file.
 *
 * `up()` and `down()` each receive the app's own `Connection` — the same one
 * the container holds, so a migration can never run against a database the app
 * does not use. `down()` is not optional: a migration without one cannot be
 * rolled back, and `lava db:rollback` would have to either lie about it or
 * refuse the batch.
 */
return new class extends Migration
{
    public function up(Connection $db): void
    {
        $db->schema()->create('tasks', function (Table $t): void {
            $t->id();
            $t->string('title', 200);
            $t->bool('done')->default(false);
            $t->date('due_on')->nullable();
            // Both nullable, and that is the convention rather than a
            // preference: a NOT NULL timestamp with no default would make
            // every insert name the column, including the ones that only ever
            // set `title`.
            $t->timestamps();

            // The list endpoint filters on `done` and orders by `id`. The id
            // is already the primary key; this index is for the filter.
            $t->index('done');
        });
    }

    public function down(Connection $db): void
    {
        $db->schema()->dropIfExists('tasks');
    }
};
