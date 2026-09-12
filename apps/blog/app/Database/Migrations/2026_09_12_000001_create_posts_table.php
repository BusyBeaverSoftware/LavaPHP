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

            // Cascade, so deleting an account takes its posts with it rather
            // than leaving rows that no join can attribute. That is a product
            // decision as much as a schema one — the alternative, keeping the
            // posts and NULLing the author, is a blog that survives its writers,
            // and this one does not.
            $t->bigInt('author_id')->references('users')->onDelete(ForeignAction::Cascade);

            $t->string('title', 200);

            // NOT NULL, and the app always supplies it. `PostRepository::hydrate()`
            // requires a string here, so a nullable column would just move the
            // failure to read time.
            $t->text('body');

            $t->bool('published')->default(false);
            $t->timestamps();

            // The listing's WHERE clause and its ORDER BY both start here.
            $t->index('published');
            $t->index('author_id');
        });
    }

    public function down(Connection $db): void
    {
        $db->schema()->dropIfExists('posts');
    }
};
