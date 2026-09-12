<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * A post id that matches no row — or a draft belonging to somebody else.
 *
 * The two cases are one problem on purpose. Distinguishing "no such post" from
 * "that post exists but is not yours" tells an anonymous caller which ids are
 * real, so the fix is the same for both and the message does not confirm either.
 */
final class PostNotFound extends LavaProblem
{
    public static function of(int $id): self
    {
        return new self(
            "No visible post with id {$id}.",
            'List the posts that exist: GET / — a draft is only visible to the user who wrote it.',
            ['id' => $id],
        );
    }

    public function code(): string
    {
        return 'post_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
