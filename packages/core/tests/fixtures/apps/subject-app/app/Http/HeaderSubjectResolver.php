<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Features\FlagSubject;
use Lava\Core\Features\FlagSubjectResolver;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The fixture's audience source: the X-User-Id header, absent for anonymous
 * visitors. Real apps would resolve the subject from a session or a token —
 * the contract is the same: request in, subject or null out.
 */
final class HeaderSubjectResolver implements FlagSubjectResolver
{
    public function subjectFor(ServerRequestInterface $request): ?FlagSubject
    {
        $id = trim($request->getHeaderLine('X-User-Id'));
        return $id === '' ? null : new FlagSubject($id);
    }
}