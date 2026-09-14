<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Testing\IsolatedEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The environment a run leaves behind is the one it found, in `getenv()` as
 * well as in `$_ENV` and `$_SERVER` (Lava Notes, R3-B17). The suite runs with
 * nothing exported, so these tests export what they need and unset it again.
 */
final class IsolatedEnvironmentTest extends TestCase
{
    public function testLavaVariablesFromOutsideAreHiddenDuringTheRunAndBackAfterIt(): void
    {
        putenv('LAVA_ENV=prod');
        putenv('LAVA_FEATURE_ISOLATION_PROBE=off');
        try {
            $seen = IsolatedEnvironment::run(
                ['LAVA_FEATURE_ISOLATION_GIVEN' => 'on'],
                static fn (): array => [getenv('LAVA_ENV'), getenv('LAVA_FEATURE_ISOLATION_PROBE'), getenv('LAVA_FEATURE_ISOLATION_GIVEN')],
            );

            self::assertSame([false, false, 'on'], $seen, 'An outer setting never steers the run.');
            self::assertSame('prod', getenv('LAVA_ENV'));
            self::assertSame('off', getenv('LAVA_FEATURE_ISOLATION_PROBE'));
            self::assertFalse(getenv('LAVA_FEATURE_ISOLATION_GIVEN'), 'What the run was given does not outlive it.');
        } finally {
            putenv('LAVA_ENV');
            putenv('LAVA_FEATURE_ISOLATION_PROBE');
            putenv('LAVA_FEATURE_ISOLATION_GIVEN');
        }
    }

    public function testAVariableTheWorkUnsetsOrChangesComesBack(): void
    {
        putenv('ISOLATION_PROBE_UNSET=keep');
        putenv('ISOLATION_PROBE_CHANGED=before');
        try {
            IsolatedEnvironment::run([], static function (): null {
                putenv('ISOLATION_PROBE_UNSET');
                putenv('ISOLATION_PROBE_CHANGED=during');
                putenv('ISOLATION_PROBE_ADDED=during');

                return null;
            });

            self::assertSame('keep', getenv('ISOLATION_PROBE_UNSET'));
            self::assertSame('before', getenv('ISOLATION_PROBE_CHANGED'));
            self::assertFalse(getenv('ISOLATION_PROBE_ADDED'));
            self::assertSame('keep', trim((string) shell_exec('printenv ISOLATION_PROBE_UNSET')), 'A child process inherits the restored environment.');
        } finally {
            putenv('ISOLATION_PROBE_UNSET');
            putenv('ISOLATION_PROBE_CHANGED');
            putenv('ISOLATION_PROBE_ADDED');
        }
    }
}
