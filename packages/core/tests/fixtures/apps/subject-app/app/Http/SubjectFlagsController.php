<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Features\Features;
use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;

/** Reads both audience flags through an injected `Features`, for whoever asked. */
final class SubjectFlagsController
{
    public function show(Features $features): ResponseInterface
    {
        return Responses::json([
            'team_preview' => $features->on('team_preview'),
            'slow_rollout' => $features->on('slow_rollout'),
        ]);
    }
}
