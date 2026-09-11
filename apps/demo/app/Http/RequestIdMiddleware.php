<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Global middleware: every response carries a request id, and the id is also on
 * the request as an attribute, so a handler can put it in a log line or an
 * error payload and a caller can quote it in a bug report.
 *
 * An inbound `X-Request-Id` is honoured when it is well formed — a gateway or a
 * test that already has an id keeps it, so one logical request is one id across
 * services. It is NOT echoed blindly: the value goes back out in a response
 * header, and a header is not a place to trust a caller's bytes. Anything that
 * is not a short, plain token is replaced with a generated one.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    /** The request attribute the id is available under, for handlers. */
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-Id';

    /**
     * The shape an inbound id must have to be kept. Deliberately narrow: this is
     * the whole of the validation, so it is worth being able to read it at once.
     */
    private const PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $inbound = $request->getHeaderLine(self::HEADER);
        $id = preg_match(self::PATTERN, $inbound) === 1 ? $inbound : bin2hex(random_bytes(8));

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $id))
            ->withHeader(self::HEADER, $id);
    }
}
