<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\CommandTestCase;

/**
 * `lava serve`'s failure paths, in process. The success path blocks by design —
 * it hands the terminal to `php -S` — so it is tested over real HTTP in
 * tests/Cli/ServeTest.php; what is testable here is everything that must happen
 * BEFORE the server is announced.
 *
 * That order is the point of the command. A server that starts and then 404s
 * everything looks like a working server with a broken app, which is the worst
 * diagnosis available: the entry point is checked first, and a bad invocation
 * is refused before anything is served at all.
 */
final class ServeCommandTest extends CommandTestCase
{
    public function testAPortOutsideTheRangeIsAUsageError(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['serve', '--port=99999']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame(['bad_usage'], array_column($envelope['problems'], 'code'));
        self::assertSame('port', $envelope['problems'][0]['context']['flag']);
        self::assertSame('99999', $envelope['problems'][0]['context']['value']);
        // The fix is the whole usage line, so the caller can copy-paste the
        // correct invocation instead of reading a sentence about it.
        self::assertStringContainsString('lava serve', (string) $envelope['problems'][0]['fix']);
    }

    public function testANonNumericPortIsAUsageError(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['serve', '--port=abc']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame('abc', $envelope['problems'][0]['context']['value']);
    }

    public function testZeroWorkersIsAUsageError(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['serve', '--workers=0']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame('workers', $envelope['problems'][0]['context']['flag']);
        self::assertSame('0', $envelope['problems'][0]['context']['value']);
        // `context` carries the failing INPUT; what was expected lives in the
        // message, and the valid invocation in `fix`.
        self::assertStringContainsString('a positive integer', (string) $envelope['problems'][0]['problem']);
        self::assertSame(4, $envelope['data']['workers']);
    }

    public function testAnUnusableValueNeverReachesThePayload(): void
    {
        // `data.port` is a port number and the schema says so (1-65535), so a
        // payload that echoed the invalid input would satisfy the shape while
        // breaking the meaning — every `--json` consumer would have to
        // re-validate a field the schema had already promised was a port. The
        // failing input is not lost; it moves to the problem's context, which is
        // where this framework puts failing inputs.
        [, $envelope] = $this->json('ok-app', ['serve', '--port=99999']);

        self::assertSame(8080, $envelope['data']['port']);
        self::assertSame('http://127.0.0.1:8080', $envelope['data']['url']);
        self::assertSame('99999', $envelope['problems'][0]['context']['value']);
    }

    public function testAUsageErrorStillCarriesTheWholePayloadShape(): void
    {
        // The payload is seeded before anything can fail, the same contract
        // AppCommand keeps: `lava serve` has two exits that never reach the
        // point where these values are computed, and a `--json` consumer must
        // not have to branch on a shape that is only sometimes there.
        [, $envelope] = $this->json('ok-app', ['serve', '--port=99999']);

        self::assertSame(
            ['host', 'port', 'workers', 'url', 'doc_root', 'entry_point', 'booted'],
            array_keys($envelope['data']),
        );
        self::assertFalse($envelope['data']['booted']);
    }

    public function testAMissingEntryPointIsReportedBeforeTheServerStarts(): void
    {
        [$code, $envelope] = $this->json('bad-commands-app', ['serve']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame(['missing_entry_point'], array_column($envelope['problems'], 'code'));
        self::assertSame('public/index.php', $envelope['problems'][0]['context']['expected']);
        self::assertStringContainsString('lava/app skeleton', (string) $envelope['problems'][0]['fix']);
        self::assertFalse($envelope['data']['booted']);
        self::assertSame('public/index.php', $envelope['data']['entry_point']);
    }

    public function testTheEntryPointIsCheckedBeforeTheAppIsBooted(): void
    {
        // bad-commands-app is ALSO broken inside (its app/Commands.php returns
        // the wrong shape). If booting came first, that problem would be in the
        // report — and the reader would be told about a file that has nothing to
        // do with why there is no server to talk to.
        [, $envelope] = $this->json('bad-commands-app', ['serve']);

        self::assertNotContains('invalid_config', array_column($envelope['problems'], 'code'));
    }

    public function testTheTextViewShowsTheProblemAndItsFix(): void
    {
        $text = $this->text('ok-app', ['serve', '--port=99999']);

        self::assertStringContainsString('bad_usage', $text);
        self::assertStringContainsString('FIX:', $text);
    }
}
