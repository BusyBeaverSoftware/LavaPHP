<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Who is signed in, as a value object with no behaviour that touches the
 * database — so a template, a controller and a test all see the same three
 * facts and none of them can reach a password hash by accident.
 *
 * `sessionEpoch` is the revocation lever. A signed cookie cannot be un-signed,
 * so it carries the epoch it was minted at, and every request compares it with
 * this column. Bumping the column invalidates every cookie that user has
 * anywhere, without any server-side session store.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $displayName,
        public int $sessionEpoch,
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'display_name' => $this->displayName,
        ];
    }
}
