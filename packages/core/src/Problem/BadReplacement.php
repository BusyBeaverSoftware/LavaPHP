<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A test asked to replace a service in a way that cannot mean what it says.
 *
 * `TestApp::boot(…, replace: [...])` swaps the value an id resolves to; it never
 * registers anything. A replacement for an id nobody registers would stand in
 * for nothing — every test using it would pass against a fake the app never
 * asks for — and a replacement of the wrong type would hand a service that
 * type-hints the id something else. Both are reported at boot, where the test
 * that wrote them is the one that fails.
 */
final class BadReplacement extends LavaProblem
{
    public static function unregistered(string $id): self
    {
        return new self(
            "A test replaced '{$id}', but nothing registers that id, so the replacement stands in for nothing.",
            "Replace only an id that app/Services.php, a pack or core registers — `lava services` lists them — "
                . "or remove '{$id}' from replace:.",
            ['id' => $id],
        );
    }

    public static function wrongType(string $id, string $given): self
    {
        return new self(
            "The replacement for '{$id}' is {$given}, which is not an instance of {$id}.",
            "Pass an instance of {$id}, so every service that type-hints it still receives one.",
            ['id' => $id, 'given' => $given],
        );
    }

    public function code(): string
    {
        return 'bad_replacement';
    }
}
