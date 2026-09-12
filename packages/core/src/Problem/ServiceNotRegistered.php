<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Code asked the container for an id that was never registered.
 * Implements PSR-11's NotFoundExceptionInterface so the container stays
 * spec-compliant while still throwing a self-correcting LavaProblem.
 *
 * When the id is a namespaced class name and no such class, interface or enum
 * exists at all, the usual cause is a missing `use` import rather than a missing
 * registration: an unqualified `FlagSubjectResolver` written in
 * `namespace App\Http` means `App\Http\FlagSubjectResolver`. Telling the reader
 * to register a class that does not exist sends them to the wrong file, so that
 * case gets its own fix — naming the registered type they most likely meant,
 * when the container holds one with the same short name.
 */
final class ServiceNotRegistered extends LavaProblem implements NotFoundExceptionInterface
{
    public function code(): string
    {
        return 'service_not_registered';
    }

    /**
     * @param list<string> $candidates registered ids that share the missing id's short name
     */
    public static function of(string $id, ?string $referencedFrom = null, ?string $note = null, array $candidates = []): self
    {
        $message = "Service '{$id}' is not registered in the container.";
        if ($note !== null) {
            $message .= " ({$note})";
        }
        $context = array_filter(['id' => $id, 'referenced_from' => $referencedFrom], static fn ($v) => $v !== null);

        if (self::namesAMissingType($id)) {
            $short = self::shortName($id);
            $context['type_exists'] = false;
            if ($candidates !== []) {
                $context['candidates'] = $candidates;
            }

            return new self(
                $message . " No class or interface named {$id} exists either.",
                $candidates !== []
                    ? "Add `use {$candidates[0]};` to the file that names {$short}, or write the type fully qualified."
                    : "If {$short} lives in another namespace, add its `use` import to the file that names it;"
                        . " if it is your own class, create it and register it in app/Services.php.",
                $context,
            );
        }

        return new self(
            $message,
            "Register it in app/Services.php: \$c->singleton({$id}::class, fn (Container \$c) => new {$id}(…)),"
            . " or remove the reference to it.",
            $context,
        );
    }

    /**
     * Whether the id is written as a namespaced class name and names no class,
     * interface or enum. Ids like `app.env` are not class names and keep the
     * ordinary fix.
     */
    private static function namesAMissingType(string $id): bool
    {
        if (preg_match('/^\\\\?[A-Z_a-z][A-Za-z0-9_]*(\\\\[A-Z_a-z][A-Za-z0-9_]*)+$/', $id) !== 1) {
            return false;
        }

        return !class_exists($id) && !interface_exists($id) && !enum_exists($id);
    }

    private static function shortName(string $id): string
    {
        $at = strrpos($id, '\\');

        return $at === false ? $id : substr($id, $at + 1);
    }
}
