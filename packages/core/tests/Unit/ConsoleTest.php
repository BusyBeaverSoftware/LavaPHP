<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\Args;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\Console;
use Lava\Core\Console\Envelope;
use Lava\Core\Console\ExitCode;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\UnknownCommand;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    public function testArgsParsePositionalsFlagsAndEqualsValues(): void
    {
        $args = Args::parse(['routes', '--all', '--env=prod', '-q']);

        self::assertSame(['routes'], $args->positional());
        self::assertTrue($args->has('all'));
        self::assertSame('prod', $args->value('env'));
        self::assertTrue($args->bool('quiet'));
    }

    public function testArgsTreatTokensAfterDoubleDashAsPositional(): void
    {
        $args = Args::parse(['--', '--json', '-x']);

        self::assertSame(['--json', '-x'], $args->positional());
        self::assertFalse($args->has('json'));
    }

    public function testArgsValueIsNullForValuelessFlag(): void
    {
        self::assertNull(Args::parse(['--json'])->value('json'));
        self::assertNull(Args::parse(['--json'])->value('absent'));
    }

    public function testTableAlignsColumnsAndRendersEmpty(): void
    {
        $table = new Table(['name', 'v'], [['a', '1'], ['bbbb', '22']]);
        self::assertSame("name  v\n----  --\na     1\nbbbb  22\n", $table->render());
        self::assertSame("(none)\n", (new Table(['a'], []))->render());
    }

    public function testEnvelopeHasTheVersionedShape(): void
    {
        $envelope = Envelope::of('routes', 'ok', ['routes' => []], []);

        self::assertSame('lava.routes/1', $envelope['schema']);
        self::assertSame('routes', $envelope['command']);
        self::assertSame('ok', $envelope['status']);
        self::assertSame(['routes' => []], $envelope['data']);
        self::assertSame([], $envelope['problems']);
        self::assertStringEndsWith("\n", Envelope::encode($envelope));
    }

    public function testJsonModeEmitsOnlyData(): void
    {
        [$io, $stdout] = $this->io(json: true);

        $io->data('answer', 42);
        $io->text("human\n");
        $code = $io->emit('demo');

        self::assertSame(ExitCode::Ok, $code);
        $out = $this->contents($stdout);
        self::assertStringNotContainsString('human', $out);
        self::assertSame(42, json_decode($out, true, flags: JSON_THROW_ON_ERROR)['data']['answer']);
    }

    public function testTextModeEmitsOnlyText(): void
    {
        [$io, $stdout] = $this->io(json: false);

        $io->data('answer', 42);
        $io->text("human\n");
        $io->emit('demo');

        self::assertSame("human\n", $this->contents($stdout));
    }

    public function testQuietSuppressesText(): void
    {
        [$io, $stdout] = $this->io(json: false, quiet: true);

        $io->text("human\n");
        $io->emit('demo');

        self::assertSame('', $this->contents($stdout));
    }

    public function testFatalProblemFailsTheEnvelopeAndExitCode(): void
    {
        [$io, $stdout] = $this->io(json: true);
        $report = new ProblemReport();
        $report->add(UnknownCommand::of('nope', null));

        $code = $io->emit('demo', $report);

        self::assertSame(ExitCode::Failure, $code);
        $decoded = json_decode($this->contents($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('failed', $decoded['status']);
        self::assertSame('unknown_command', $decoded['problems'][0]['code']);
    }

    public function testTextModeRendersProblemsAfterText(): void
    {
        [$io, $stdout] = $this->io(json: false);
        $report = new ProblemReport();
        $report->add(UnknownCommand::of('nope', 'list'));

        $io->text("before\n");
        $io->emit('demo', $report);

        $out = $this->contents($stdout);
        self::assertStringStartsWith("before\n", $out);
        self::assertStringContainsString('FIX:', $out);
        self::assertStringContainsString('unknown_command', $out);
    }

    public function testConsoleListsCommandsAsAnEnvelope(): void
    {
        [$io, $stdout] = $this->io(json: true);

        $code = $this->console()->run(['lava', 'list', '--json'], $io);

        self::assertSame(ExitCode::Ok, $code);
        $decoded = json_decode($this->contents($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('lava.list/1', $decoded['schema']);
        $names = array_column($decoded['data']['commands'], 'name');
        self::assertContains('list', $names);
    }

    public function testBareLavaDefaultsToList(): void
    {
        [$io, $stdout] = $this->io(json: true);

        $code = $this->console()->run(['lava', '--json'], $io);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame('lava.list/1', json_decode($this->contents($stdout), true, flags: JSON_THROW_ON_ERROR)['schema']);
    }

    public function testUnknownCommandIsAUsageErrorWithAFix(): void
    {
        [$io, $stdout] = $this->io(json: true);

        $code = $this->console()->run(['lava', 'lits', '--json'], $io);

        self::assertSame(ExitCode::Usage, $code);
        $decoded = json_decode($this->contents($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('unknown_command', $decoded['problems'][0]['code']);
        // 'lits' is two edits from 'list' — the nearest-name hint must fire.
        self::assertSame('list', $decoded['problems'][0]['context']['nearest']);
        self::assertStringContainsString('lava list', $decoded['problems'][0]['fix']);
    }

    public function testHelpPrintsUsageAndSummary(): void
    {
        [$io, $stdout] = $this->io(json: false);

        $code = $this->console()->run(['lava', 'list', '--help'], $io);

        self::assertSame(ExitCode::Ok, $code);
        $out = $this->contents($stdout);
        self::assertStringContainsString('lava list', $out);
        self::assertStringContainsString('grouped by pack', $out);
    }

    private function console(): Console
    {
        return new Console(CommandRegistry::core(), '/tmp/app');
    }

    /**
     * @return array{IO, resource, resource}
     */
    private function io(bool $json, bool $quiet = false): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        return [new IO($json, $quiet, $stdout, $stderr), $stdout, $stderr];
    }

    /** @param resource $stream */
    private function contents($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }
}
