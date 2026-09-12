<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;

function health(): ResponseInterface
{
    return Responses::json(['status' => 'ok']);
}
