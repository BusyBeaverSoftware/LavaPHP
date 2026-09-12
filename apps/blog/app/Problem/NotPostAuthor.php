<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * Someone is signed in and trying to change a post they did not write.
 *
 * 403, and distinct from {@see PostNotFound} because the caller has already been
 * told the post exists (they are looking at it) — so hiding it now would only be
 * confusing. This is the check an authorization bug would skip, which is why it
 * is its own type with its own code.
 */
final class NotPostAuthor extends LavaProblem
{
    public static function of(int $id, int $userId): self
    {
        return new self(
            "Post {$id} belongs to another user.",
            'Edit one of your own posts instead: GET / lists the ones you may change.',
            ['post_id' => $id, 'user_id' => $userId],
        );
    }

    public function code(): string
    {
        return 'not_post_author';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
