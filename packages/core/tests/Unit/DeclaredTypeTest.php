<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * The type a factory declares, as the map's `class` column shows it.
 *
 * Found by an outside build (Lava Notes, R2-B10): a factory written inside a
 * class with `fn (): static => …` put `static` in the map — a word, not a class
 * anyone can open. The closure's scope is fixed where it is written, so naming
 * the class keeps the column independent of the environment.
 */
final class DeclaredTypeTest extends TestCase
{
    public function testSelfStaticAndParentNameTheClassTheFactoryWasWrittenIn(): void
    {
        $container = new Container();
        DeclaredTypeFactory::wire($container);

        self::assertSame(DeclaredTypeFactory::class, $container->declaredType('mail.self'));
        self::assertSame(DeclaredTypeFactory::class, $container->declaredType('mail.static'));
        self::assertSame(DeclaredTypeBase::class, $container->declaredType('mail.parent'));
        self::assertSame('?' . DeclaredTypeFactory::class, $container->declaredType('mail.nullable'));
        self::assertSame(DeclaredTypeFactory::class . '|' . \ArrayObject::class, $container->declaredType('mail.union'));
    }

    public function testATypeWrittenOutIsShownAsWritten(): void
    {
        $container = new Container();
        $container->singleton('plain', static fn (): \ArrayObject => new \ArrayObject());
        $container->singleton('untyped', static fn () => new \ArrayObject());

        self::assertSame(\ArrayObject::class, $container->declaredType('plain'));
        self::assertNull($container->declaredType('untyped'));
    }
}

class DeclaredTypeBase
{
}

final class DeclaredTypeFactory extends DeclaredTypeBase
{
    public static function wire(Container $container): void
    {
        $container->singleton('mail.self', static fn (): self => new self());
        $container->singleton('mail.static', static fn (): static => new static());
        $container->singleton('mail.parent', static fn (): parent => new DeclaredTypeBase());
        $container->singleton('mail.nullable', static fn (): ?self => null);
        $container->singleton('mail.union', static fn (): self|\ArrayObject => new \ArrayObject());
    }
}
