<?php

declare(strict_types=1);

namespace Lava\Core\Features;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Derives who is asking, per request: the app-registered service that turns a
 * request into a FlagSubject — or null for anonymous. Audience flags (rollout,
 * users) resolve off for anonymous subjects, by documented policy (see
 * FlagSubject).
 *
 * Register it in app/Services.php under FlagSubjectResolver::class: the
 * container id is the interface itself, so the app and the core look it up by
 * the same name. Not registering one is valid — every audience flag then
 * resolves off for every request (fail-closed, like gated routes when no
 * Features is in hand).
 */
interface FlagSubjectResolver
{
    public function subjectFor(ServerRequestInterface $request): ?FlagSubject;
}