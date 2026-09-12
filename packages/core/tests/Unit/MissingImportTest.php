<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Container\Container;
use Lava\Core\Http\Responses;
use Lava\Core\Problem\ServiceNotRegistered;
use Lava\Core\Routing\HandlerInvoker;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A handler parameter typed with a class nobody imported is the commonest way to
 * meet `service_not_registered`, and the fix has to point at the import, not at
 * a registration for a class that does not exist.
 *
 * `MissingImportController::show()` below names `FlagSubjectResolver` without a
 * `use` line, so PHP reads it as `Lava\Core\Tests\Unit\FlagSubjectResolver` —
 * exactly what happens to `App\Http\...` in an app.
 */
final class MissingImportTest extends TestCase
{
    public function testAnUnimportedTypeNamesTheImportItMostLikelyNeeds(): void
    {
        $container = new Container();
        $container->value(\Lava\Core\Features\FlagSubjectResolver::class, 'a stand-in');

        $problem = $this->planFailure($container, 'show');

        self::assertSame('Lava\Core\Tests\Unit\FlagSubjectResolver', $problem->context['id']);
        self::assertFalse($problem->context['type_exists']);
        self::assertSame([\Lava\Core\Features\FlagSubjectResolver::class], $problem->context['candidates']);
        self::assertStringContainsString('No class or interface named', $problem->getMessage());
        self::assertStringContainsString('use Lava\Core\Features\FlagSubjectResolver;', $problem->fix);
        self::assertStringNotContainsString('Register it', $problem->fix);
    }

    public function testWithNoLookalikeRegisteredTheFixStillPointsAtTheImport(): void
    {
        $problem = $this->planFailure(new Container(), 'show');

        self::assertArrayNotHasKey('candidates', $problem->context);
        self::assertStringContainsString('add its `use` import', $problem->fix);
    }

    public function testARealClassThatIsOnlyUnregisteredKeepsTheRegistrationFix(): void
    {
        $problem = $this->planFailure(new Container(), 'registered');

        self::assertSame(MissingImportService::class, $problem->context['id']);
        self::assertArrayNotHasKey('type_exists', $problem->context);
        self::assertStringStartsWith('Register it in app/Services.php', $problem->fix);
    }

    public function testAnIdThatIsNotAClassNameKeepsTheRegistrationFix(): void
    {
        $problem = ServiceNotRegistered::of('app.mailer', 'app/Services.php:12');

        self::assertArrayNotHasKey('type_exists', $problem->context);
        self::assertStringStartsWith('Register it in app/Services.php', $problem->fix);
    }

    private function planFailure(Container $container, string $method): ServiceNotRegistered
    {
        try {
            (new HandlerInvoker($container))->plan([MissingImportController::class, $method]);
        } catch (ServiceNotRegistered $problem) {
            return $problem;
        }

        self::fail("planning {$method}() should have failed with service_not_registered");
    }
}

final class MissingImportController
{
    // No `use` for FlagSubjectResolver, on purpose — see the test's docblock.
    public function show(FlagSubjectResolver $resolver): ResponseInterface
    {
        return Responses::json([]);
    }

    public function registered(MissingImportService $service): ResponseInterface
    {
        return Responses::json([]);
    }
}

final class MissingImportService
{
}
