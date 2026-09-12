<?php

declare(strict_types=1);

use Lava\Db\Connection;
use Lava\Db\Migration\Migration;
use Lava\Db\Schema\Table;

return new class extends Migration
{
    public function up(Connection $db): void
    {
        $db->schema()->create('users', function (Table $t): void {
            $t->id();

            // 254 is the RFC 5321 maximum for an address, and the unique index
            // is the real guarantee that two accounts cannot share one: the
            // application-level check in AuthController::register() can be raced,
            // and this cannot.
            $t->string('email', 254)->unique();
            $t->string('display_name', 60);
            $t->string('password_hash', 255);

            // Bumped whenever the password changes. A session cookie carries the
            // value it was minted at, so this column is what makes a signed
            // cookie revocable without a server-side session store.
            $t->int('session_epoch')->default(0);

            // Two nullable datetimes and nothing more — `timestamps()` does not
            // attach defaults, so the app writes them. Noted here because the
            // name suggests otherwise.
            $t->timestamps();
        });
    }

    public function down(Connection $db): void
    {
        $db->schema()->dropIfExists('users');
    }
};
