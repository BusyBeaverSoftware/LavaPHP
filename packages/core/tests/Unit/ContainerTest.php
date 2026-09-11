<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Container\Container;
use Lava\Core\Container\ServiceKind;
use Lava\Core\Problem\CircularService;
use Lava\Core\Problem\DuplicateService;
use Lava\Core\Problem\ServiceNotRegistered;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

final class ContainerTest extends TestCase
{
    public function testSingletonIsSharedAndFactoryIsNot(): void
    {
        $c = new Container();
        $c->singleton('s', fn (): Dep => new Dep());
        $c->factory('f', fn (): Dep => new Dep());

        self::assertSame($c->get('s'), $c->get('s'));
        self::assertNotSame($c->get('f'), $c->get('f'));
        self::assertTrue($c->has('s'));
        self::assertFalse($c->has('never-registered'));
    }

    public function testValuesResolveDirectlyAndAliasesRedirect(): void
    {
        $c = new Container();
        $c->value('n', 7);
        $c->alias('the.seven', 'n');

        self::assertSame(7, $c->get('n'));
        self::assertSame(7, $c->get('the.seven'));
        self::assertTrue($c->has('the.seven'));
    }

    public function testReRegistrationIsFatalWithBothSites(): void
    {
        $c = new Container();
        $c->value('x', 1);

        try {
            $c->value('x', 2);
            self::fail('DuplicateService expected');
        } catch (DuplicateService $problem) {
            self::assertSame('duplicate_service', $problem->code());
            self::assertStringContainsString('registered twice', $problem->getMessage());
            self::assertSame(__FILE__, $problem->source->file);
        }
    }

    public function testUnknownIdIsAPsr11NotFound(): void
    {
        $c = new Container();

        try {
            $c->get('missing');
            self::fail('ServiceNotRegistered expected');
        } catch (ServiceNotRegistered $problem) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $problem);
            self::assertSame('service_not_registered', $problem->code());
        }
    }

    public function testCircularResolutionNamesEveryHop(): void
    {
        $c = new Container();
        $c->singleton('a', fn (Container $c): Dep => new Dep($c->get('b')));
        $c->singleton('b', fn (Container $c): Dep => new Dep($c->get('a')));

        try {
            $c->get('a');
            self::fail('CircularService expected');
        } catch (CircularService $problem) {
            self::assertSame('circular_service', $problem->code());
            self::assertSame(['a', 'b', 'a'], $problem->context['chain']);
        }
    }

    public function testIdsPreserveRegistrationOrder(): void
    {
        $c = new Container();
        $c->value('first', 1);
        $c->value('second', 2);
        $c->alias('third', 'first');

        self::assertSame(['first', 'second', 'third'], $c->ids());
    }

    public function testDescribeNamesTheFileTheFactoryWasWrittenIn(): void
    {
        $c = new Container();
        $c->singleton(Dep::class, fn (): Dep => new Dep());

        $record = $c->describe(Dep::class);
        self::assertSame(ServiceKind::Singleton, $record->kind);
        self::assertSame(__FILE__, $record->file);
        self::assertNull($record->class); // not resolved yet

        $c->get(Dep::class);
        self::assertSame(Dep::class, $c->describe(Dep::class)->class);
    }

    public function testTracesComeFromRealResolutions(): void
    {
        $c = new Container();
        $c->singleton('a', fn (Container $c): Dep => new Dep($c->get('b')));
        $c->singleton('b', fn (): Dep => new Dep());
        $c->get('a');

        self::assertSame(['b'], $c->traces()['a']->dependencies());
        self::assertSame(['a'], $c->traces()['b']->dependents());
    }
}

/** Test stand-in with one optional dependency. */
final class Dep
{
    public function __construct(public readonly mixed $value = null)
    {
    }
}