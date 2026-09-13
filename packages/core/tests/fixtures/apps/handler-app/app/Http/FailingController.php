<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

final class FailingController
{
    public function boom(): ResponseInterface
    {
        // The kind of message a driver really writes: the credential is in it.
        throw new \RuntimeException('could not connect to pgsql://blog:hunter2@db.internal/blog');
    }
}
