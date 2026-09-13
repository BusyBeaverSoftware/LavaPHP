<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;

final class ClockController
{
    public function now(ClockInterface $clock): ResponseInterface
    {
        return Responses::json(['now' => $clock->now()->format('Y-m-d H:i:s')]);
    }
}
