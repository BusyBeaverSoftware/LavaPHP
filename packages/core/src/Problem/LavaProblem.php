<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * The only exception type the framework throws at user-authored code.
 *
 * A LavaProblem carries everything an agent needs to self-correct in one
 * round trip: WHAT failed (message), WHERE (source — the user's file, not
 * the framework's), the failing INPUT (context), and the FIX (imperative).
 * The stable shape is {@see json()} — identical in boot reports, `lava check`,
 * HTTP error pages, and every `--json` command.
 */
abstract class LavaProblem extends \RuntimeException
{
    /**
     * @param string $message WHAT failed, one sentence.
     * @param string $fix IMPERATIVE fix, e.g. "Run: composer require lavaphp/db".
     * @param array<string, mixed> $context The failing input, JSON-safe key/value pairs.
     * @param SourceLocation|null $source The user-authored artifact at fault, if known.
     */
    public function __construct(
        string $message,
        public readonly string $fix,
        public readonly array $context = [],
        public readonly ?SourceLocation $source = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Stable snake_case identifier — the JSON discriminator. Catalog: docs/problem-codes.md. */
    abstract public function code(): string;

    public function severity(): Severity
    {
        return Severity::Fatal;
    }

    /**
     * The HTTP status this problem becomes when it reaches a response.
     *
     * A problem knows its own status, and that is the point: the alternative is
     * a `match` on `code()` somewhere in the HTTP layer, which would make core
     * enumerate the codes of packs it has never heard of. `validation_failed`
     * is a 422 and lives in a pack — so a pack must be able to say so.
     *
     * 500 is the default because most problems ARE framework or wiring faults:
     * something the developer got wrong, which is a server-side failure. The
     * problems that are the *caller's* fault override this — a 404 for a path
     * that matched nothing, a 405 for a method that did not, a 422 for input
     * that arrived but was not usable.
     */
    public function httpStatus(): int
    {
        return 500;
    }

    /**
     * The stable problem object. Field order is fixed; never add fields here
     * without a major schema bump in the CLI envelopes that embed it.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return [
            'code' => $this->code(),
            'problem' => $this->getMessage(),
            'fix' => $this->fix,
            'context' => $this->context,
            'source' => $this->source?->json(),
            'severity' => $this->severity()->value,
        ];
    }
}