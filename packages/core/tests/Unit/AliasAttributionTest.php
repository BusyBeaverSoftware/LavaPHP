<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Where an alias was registered, as opposed to where its target was.
 *
 * Found by an outside build (Lava Notes, R2-B11): `lava services`, the map and
 * `lava describe` named the TARGET's registration line on every alias row, so
 * core's default `LoggerInterface` alias read as registered in
 * RegisterCoreServices.php, and an app aliasing an id to a pack's service named
 * the pack's file as its own wiring.
 */
final class AliasAttributionTest extends TestCase
{
    public function testAnAliasIsDeclaredWhereAliasWasCalled(): void
    {
        $container = new Container();
        $container->singleton('app.mailer', static fn (): \stdClass => new \stdClass());
        $container->alias('app.preferred_mailer', 'app.mailer');
        $aliasLine = __LINE__ - 1;

        self::assertSame(__FILE__, $container->declaredAt('app.preferred_mailer')->file);
        self::assertSame($aliasLine, $container->declaredAt('app.preferred_mailer')->line);

        // describe() still answers for the target, registered the line before.
        self::assertSame('app.mailer', $container->describe('app.preferred_mailer')->id);
        self::assertSame($aliasLine - 1, $container->describe('app.preferred_mailer')->line);
        self::assertSame($aliasLine - 1, $container->declaredAt('app.mailer')->line);
    }
}
