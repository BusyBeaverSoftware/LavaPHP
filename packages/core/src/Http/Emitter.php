<?php

declare(strict_types=1);

namespace Lava\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response to the SAPI — the only code in the framework that
 * touches header()/echo. ~40 lines, owned: no emitter library needed.
 */
final class Emitter
{
    public static function emit(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());

        $sent = [];
        foreach ($response->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            $replace = !isset($sent[$lower]); // the first value replaces any SAPI default, later ones append
            foreach ($values as $value) {
                header("{$name}: {$value}", $replace);
                $replace = false;
                $sent[$lower] = true;
            }
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        echo $body->getContents();
    }
}