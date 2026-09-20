<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A request path the framework refuses to normalise into one meaning.
 *
 * The path is decoded once, before matching, so a route type validates the
 * same value `UrlGenerator` built (entry 314). Decoding makes two shapes
 * dangerous, and both are refused here rather than routed:
 *
 *  - **A dot segment.** `%2e%2e%2f` survives every proxy and browser that
 *    collapses a literal `../`, so a `{rest:path}` param would hand an app
 *    `../secret` from a request that looked harmless on the wire.
 *  - **A control byte.** A decoded NUL truncates a filename in any C library
 *    underneath; a decoded CR or LF splits a log line, and would split a header
 *    in any code that writes one by hand.
 *
 * 400 rather than 404: the request is malformed, and saying so tells an agent
 * what to change. Nothing about which routes exist is revealed either way.
 */
final class BadRequestPath extends LavaProblem
{
    public static function dotSegment(string $path): self
    {
        return new self(
            "The request path '{$path}' contains a '.' or '..' segment once decoded.",
            'Send the path you mean, already resolved: a relative segment is not a route, and percent-encoding one hides it from every proxy in front of this app.',
            ['path' => $path, 'reason' => 'dot_segment'],
        );
    }

    public static function controlByte(string $path): self
    {
        return new self(
            'The request path contains a control character once decoded.',
            'Send a path whose decoded bytes are printable: a NUL truncates a filename in the C library under any language, and a newline forges a line in a log.',
            ['reason' => 'control_byte'],
        );
    }

    public function code(): string
    {
        return 'bad_request_path';
    }

    /** The caller's mistake, not the app's. */
    public function httpStatus(): int
    {
        return 400;
    }
}
