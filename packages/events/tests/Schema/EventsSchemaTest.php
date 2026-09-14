<?php

declare(strict_types=1);

namespace Lava\Events\Tests\Schema;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\EnvelopeSchemas;
use Lava\Core\Tests\Support\LavaCli;
use PHPUnit\Framework\TestCase;

/**
 * `lava events --json` against the contract it claims, run through the real
 * binary in the app that enables the pack — core's JsonSchemaTest runs in an
 * app with no packs and cannot see this command.
 */
final class EventsSchemaTest extends TestCase
{
    private static function app(): string
    {
        return dirname(__DIR__) . '/fixtures/apps/events-app';
    }

    public function testTheEnvelopeObeysTheSchemaItClaims(): void
    {
        $result = LavaCli::run(['events', '--json'], self::app());

        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        EnvelopeSchemas::assertObeys($result, '`lava events --json`');
        self::assertNotSame([], $result->data()['events']);
    }

    public function testEveryEventsCommandIsDocumentedAndNothingElseIs(): void
    {
        $listed = LavaCli::run(['list', '--json'], self::app());

        $claimed = [];
        foreach ($listed->data()['commands'] as $command) {
            self::assertIsArray($command);
            if (($command['pack'] ?? null) === 'events') {
                $claimed[] = (string) $command['schema'];
            }
        }

        self::assertSame(['lava.events/1'], $claimed);
        self::assertSame(['lava.events/1'], array_values(array_filter(
            EnvelopeSchemas::schemaNames(),
            static fn (string $schema): bool => str_starts_with($schema, 'lava.events'),
        )));
        self::assertFileExists(EnvelopeSchemas::file('lava.events/1'));
    }
}
