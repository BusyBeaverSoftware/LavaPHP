<?php

declare(strict_types=1);

namespace App\Blog;

/**
 * A post, with its author's display name already joined in.
 *
 * `authorId` and `authorName` are both here rather than a `User`: a listing
 * renders the name and nothing else, and carrying a `User` would mean an id, an
 * email and a session epoch travelling to a template that must never see the
 * last one.
 */
final readonly class Post
{
    public function __construct(
        public int $id,
        public int $authorId,
        public string $authorName,
        public string $title,
        public string $body,
        public bool $published,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    public function isOwnedBy(?int $userId): bool
    {
        return $userId !== null && $userId === $this->authorId;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'id' => $this->id,
            'author_id' => $this->authorId,
            'author_name' => $this->authorName,
            'title' => $this->title,
            'body' => $this->body,
            'published' => $this->published,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
