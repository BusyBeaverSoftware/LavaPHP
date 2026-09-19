<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A form post larger than PHP's `post_max_size`, which PHP discarded before the
 * app ran.
 *
 * PHP does not refuse such a request: it drops the parsed form — `$_POST` and
 * `$_FILES` both empty — logs a warning where nobody reads it, and hands the
 * app a request that looks like a form with no fields. Every layer after that
 * then reports the wrong thing: validation says the title is required, a CSRF
 * check says the token is missing, and the visitor is told to fix a field they
 * filled in. The truth is knowable, because the request still carries its
 * `Content-Length` and the limit is readable, so it is said here instead
 * (Lava Notes, R3-G2).
 *
 * 413 rather than 400: the request was well-formed, and what was wrong with it
 * is its size. The limit belongs to the deployment, not to the app's code —
 * `post_max_size` cannot be set at runtime — so the fix names the ini setting.
 */
final class RequestTooLarge extends LavaProblem
{
    public static function form(string $contentType, int $length, int $limit): self
    {
        return new self(
            "The request body is {$length} bytes and PHP's post_max_size is {$limit}, so PHP discarded the form before the app could read it.",
            'Send a smaller body, or raise post_max_size (and upload_max_filesize for uploads) in php.ini above the largest'
            . ' form this app accepts — neither can be changed from code, so the deployment sets them.',
            ['content_type' => $contentType, 'content_length' => $length, 'post_max_size' => $limit],
        );
    }

    public function code(): string
    {
        return 'request_too_large';
    }

    /** The request was well-formed HTTP; its size is what was wrong. */
    public function httpStatus(): int
    {
        return 413;
    }
}
