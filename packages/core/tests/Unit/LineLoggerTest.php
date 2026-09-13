<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Log\LineLogger;
use PHPUnit\Framework\TestCase;

/**
 * The built-in PSR-3 logger.
 *
 * `LineLogger` is what the container registers for `Psr\Log\LoggerInterface`,
 * so every app that does not swap it out is running this class — and until this
 * test existed, no test executed a single line of `log()`. The only other
 * mention of it in the suite asserts that the container ALIAS resolves to it
 * (see InspectionCommandsTest), which is a claim about the wiring and not about
 * the logger. A default nobody runs is a default whose first bug is found by a
 * user.
 *
 * The stream is `php://memory`, not stderr: the assertions read the bytes the
 * logger actually wrote, rather than whatever the test runner happened to do
 * with them.
 */
final class LineLoggerTest extends TestCase
{
    /** @return resource a fresh, readable in-memory stream */
    private static function stream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    /** @param resource $stream */
    private static function written($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function testItWritesOneTimestampedLineWithTheLevelAndTheMessage(): void
    {
        $stream = self::stream();
        (new LineLogger('debug', $stream))->log('warning', 'disk is nearly full');

        $written = self::written($stream);
        self::assertStringContainsString('warning: disk is nearly full', $written);
        self::assertStringEndsWith("\n", $written);
        // One line, one entry: a logger that appends without a newline turns a
        // log into one unreadable line.
        self::assertCount(1, array_filter(explode("\n", $written), static fn (string $l): bool => $l !== ''));
        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}\] /', $written);
    }

    public function testContextIsAppendedAsJson(): void
    {
        $stream = self::stream();
        (new LineLogger('debug', $stream))->log('error', 'query failed', ['sql' => 'select 1', 'ms' => 12]);

        // Appended rather than interpolated: a context value that is itself a
        // structure has to survive, and `{$message}`-style interpolation cannot
        // carry one.
        self::assertStringContainsString('{"sql":"select 1","ms":12}', self::written($stream));
    }

    public function testAnExceptionInTheContextIsWrittenWithItsClassMessageLocationAndTrace(): void
    {
        // PSR-3 reserves `exception` for a Throwable. JSON-encoded as it is, an
        // exception object prints as `{}` — which is what 0.2.0 wrote (R2-B6).
        $stream = self::stream();
        $exception = new \RuntimeException('session store unavailable', 0, new \LogicException('inner'));
        (new LineLogger('debug', $stream))->log('error', 'failed', ['code' => 'x', 'exception' => $exception]);

        $written = self::written($stream);
        self::assertCount(1, array_filter(explode("\n", $written), static fn (string $l): bool => $l !== ''));

        $json = json_decode(substr($written, (int) strpos($written, '{')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('x', $json['code']);
        self::assertSame(\RuntimeException::class, $json['exception']['class']);
        self::assertSame('session store unavailable', $json['exception']['message']);
        self::assertSame(__FILE__ . ':' . $exception->getLine(), $json['exception']['at']);
        self::assertIsList($json['exception']['trace']);
        self::assertSame(\LogicException::class, $json['exception']['previous']['class']);
    }

    public function testAnEmptyContextAddsNothingToTheLine(): void
    {
        $stream = self::stream();
        (new LineLogger('debug', $stream))->log('info', 'started');

        self::assertStringNotContainsString('{', self::written($stream));
    }

    public function testALevelBelowTheMinimumIsDroppedEntirely(): void
    {
        $stream = self::stream();
        $logger = new LineLogger('warning', $stream);

        $logger->log('info', 'not interesting');
        self::assertSame('', self::written($stream));

        // The control: the same logger DOES write the level that clears the
        // minimum, so an empty stream above is the filter working rather than a
        // logger that writes nothing at all.
        $logger->log('error', 'interesting');
        self::assertStringContainsString('error: interesting', self::written($stream));
    }

    public function testTheMinimumIsInclusive(): void
    {
        $stream = self::stream();
        (new LineLogger('warning', $stream))->log('warning', 'exactly at the minimum');

        self::assertStringContainsString('warning: exactly at the minimum', self::written($stream));
    }

    public function testAnUnknownLevelNameIsRefusedAndTheAlternativesAreListed(): void
    {
        // PSR-3 takes `mixed $level` precisely so a bad value is caught here
        // rather than silently dropped — and the message has to carry the list,
        // because the caller's next move is to pick a real one.
        try {
            (new LineLogger('debug', self::stream()))->log('verbose', 'x');
            self::fail('a level that is not a PSR-3 level must be refused, not written');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString("Unknown log level 'verbose'", $error->getMessage());
            self::assertStringContainsString('emergency', $error->getMessage());
        }
    }

    public function testALevelThatIsNotAStringIsRefusedWithItsTypeNamed(): void
    {
        // The same guard, one branch over: a non-string has no name to quote,
        // so the message reports its TYPE. Without this branch the message would
        // read `Unknown log level ''`, which names nothing the reader can act on.
        try {
            (new LineLogger('debug', self::stream()))->log(3, 'x');
            self::fail('a non-string level must be refused, not coerced');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Unknown log level int', $error->getMessage());
        }
    }

    public function testAnUnknownMinimumLevelIsRefusedWhenTheLoggerIsBuilt(): void
    {
        // Eagerly, at construction: boot validates `config/logging.php`'s level,
        // so the failure names the config value rather than the first log line
        // that happened to be filtered out.
        try {
            new LineLogger('loud', self::stream());
            self::fail('a minimum level that is not a PSR-3 level must be refused');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString("Unknown log level 'loud'", $error->getMessage());
            self::assertStringContainsString('emergency', $error->getMessage());
        }
    }

    public function testAStreamThatIsNotAResourceIsRefused(): void
    {
        // `mixed $stream` rather than `resource`, because PHP has no `resource`
        // parameter type — so the check is a runtime one, and it is the only
        // thing standing between a misconfigured logging service and a
        // `fwrite(): Argument #1 must be of type resource` deep in a request.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('LineLogger needs an open stream resource.');

        new LineLogger('debug', 'not a stream');
    }
}
