<?php

declare(strict_types=1);

namespace App\EventsFixture;

use Lava\Core\Http\Responses;
use Lava\Core\Routing\RouteArgs;
use Lava\Events\EventDispatcher;
use Psr\Http\Message\ResponseInterface;

final class CompletionController
{
    public function complete(RouteArgs $args, EventDispatcher $events, Journal $journal): ResponseInterface
    {
        $events->dispatch(new TaskCompleted($args->int('id'), 'Write the docs'));

        return Responses::json(['journal' => $journal->lines]);
    }
}
