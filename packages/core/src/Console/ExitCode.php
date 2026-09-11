<?php

declare(strict_types=1);

namespace Lava\Core\Console;

/**
 * Exit codes are a contract: an agent branches on the number without parsing.
 */
final class ExitCode
{
    /** The command did its job (problems, if any, were warnings). */
    public const Ok = 0;

    /** The command ran but the result is a failure (fatal problem, red tests). */
    public const Failure = 1;

    /** The invocation itself was wrong (unknown command, bad flag). */
    public const Usage = 2;
}
