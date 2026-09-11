<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Container\Container;
use Lava\Core\Http\Responses;
use Lava\Core\Problem\BadHandler;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ServiceNotRegistered;
use Lava\Core\Routing\HandlerInvoker;
use Lava\Core\Routing\HandlerPlan;
use Lava\Core\Routing\RouteArgs;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The handler contract, enforced at boot by reflection: every way a handler
 * can be wrong, and the plan it gets when it is right.
 */
final class HandlerInvokerTest extends TestCase
{
    private function invoker(): HandlerInvoker
    {
        $container = new Container();
        $container->singleton(InvokerService::class, static fn (): InvokerService => new InvokerService());
        return new HandlerInvoker($container);
    }

    public function testValidMethodHandlerPlan(): void
    {
        $plan = $this->invoker()->plan([InvokerValidController::class, 'show']);

        self::assertInstanceOf(HandlerPlan::class, $plan);
        self::assertSame('method', $plan->kind);
        self::assertSame(InvokerValidController::class, $plan->class);
        self::assertSame(InvokerValidController::class . '::show', $plan->describe());
        self::assertNotNull($plan->file);
        self::assertNotNull($plan->line);
        self::assertSame([
            ['kind' => 'request', 'type' => ServerRequestInterface::class, 'name' => 'request'],
            ['kind' => 'args', 'type' => RouteArgs::class, 'name' => 'args'],
        ], $plan->injects);
    }

    public function testValidFunctionHandlerPlan(): void
    {
        $plan = $this->invoker()->plan('Lava\Core\Tests\Unit\invoker_test_function');

        self::assertSame('function', $plan->kind);
        self::assertNull($plan->class);
        self::assertSame("'Lava\\Core\\Tests\\Unit\\invoker_test_function'", $plan->describe());
        self::assertSame([['kind' => 'request', 'type' => ServerRequestInterface::class, 'name' => 'request']], $plan->injects);
    }

    public function testServiceParamsResolveToContainerIds(): void
    {
        $plan = $this->invoker()->plan([InvokerValidController::class, 'withService']);

        self::assertSame([
            ['kind' => 'service', 'type' => InvokerService::class, 'name' => 'service'],
        ], $plan->injects);
    }

    public function testInvokeDispatchesFromTheFrozenPlan(): void
    {
        $invoker = $this->invoker();
        $plan = $invoker->plan([InvokerValidController::class, 'show']);

        $response = $invoker->invoke(
            $plan,
            new ServerRequest('GET', '/hello/ada'),
            new RouteArgs('hello', ['who' => 'ada']),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('valid:ada', (string) $response->getBody());
    }

    #[DataProvider('violationsProvider')]
    public function testContractViolations(array|string $handler, string $exception, string $messageFragment): void
    {
        try {
            $this->invoker()->plan($handler);
            self::fail("Expected {$exception} for handler '" . (is_string($handler) ? $handler : (string) json_encode($handler)) . "'.");
        } catch (LavaProblem $problem) {
            self::assertInstanceOf($exception, $problem);
            self::assertStringContainsString($messageFragment, $problem->getMessage());
            // Every violation carries a non-empty imperative fix — the
            // one-round-trip agent feedback loop is part of the contract.
            self::assertNotSame('', trim($problem->fix));
            if ($problem instanceof ServiceNotRegistered) {
                self::assertStringContainsString('app/Services.php', $problem->fix);
            }
        }
    }

    /** @return array<string, array{array|string, string, string}> */
    public static function violationsProvider(): array
    {
        return [
            'malformed array handler' => [['OnlyOne'], BadHandler::class, 'array handlers'],
            'missing class' => [['App\GhostClass', 'show'], BadHandler::class, 'does not exist'],
            'abstract class' => [[InvokerAbstractController::class, 'show'], BadHandler::class, 'cannot be instantiated'],
            'constructor arguments' => [[InvokerCtorController::class, 'show'], BadHandler::class, 'constructor has required parameters'],
            'missing method' => [[InvokerValidController::class, 'nope'], BadHandler::class, 'method does not exist'],
            'no return type' => [[InvokerNoReturnController::class, 'show'], BadHandler::class, 'no return type'],
            'wrong return type' => [[InvokerWrongReturnController::class, 'show'], BadHandler::class, "declares return type 'string'"],
            'nullable return type' => [[InvokerNullableReturnController::class, 'show'], BadHandler::class, 'nullable return type'],
            'untyped param' => [[InvokerUntypedParamController::class, 'show'], BadHandler::class, 'has no type'],
            'built-in param' => [[InvokerBuiltinParamController::class, 'show'], BadHandler::class, 'built-in parameter'],
            'default value param' => [[InvokerDefaultParamController::class, 'show'], BadHandler::class, 'default value'],
            'variadic param' => [[InvokerVariadicController::class, 'show'], BadHandler::class, 'variadic'],
            'union param' => [[InvokerUnionController::class, 'show'], BadHandler::class, 'union'],
            'unregistered service param' => [[InvokerUnregisteredServiceController::class, 'show'], ServiceNotRegistered::class, 'not registered in the container'],
            'missing function' => ['invoker_no_such_function', BadHandler::class, 'not defined'],
        ];
    }
}

// Handler fixtures — every shape the contract allows or rejects. Class names
// are unique to this file (fixture classes share the App\ namespace elsewhere).

final class InvokerService
{
}

final class InvokerMissingService
{
}

final class InvokerValidController
{
    public function show(ServerRequestInterface $request, RouteArgs $args): ResponseInterface
    {
        return Responses::text('valid:' . $args->str('who'));
    }

    public function withService(InvokerService $service): ResponseInterface
    {
        return Responses::text('svc');
    }
}

final class InvokerCtorController
{
    public function __construct(private string $needed)
    {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return Responses::text('x');
    }
}

abstract class InvokerAbstractController
{
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return Responses::text('x');
    }
}

final class InvokerNoReturnController
{
    public function show(ServerRequestInterface $request) // no return type — the violation under test
    {
        return Responses::text('x');
    }
}

final class InvokerWrongReturnController
{
    public function show(ServerRequestInterface $request): string
    {
        return 'x';
    }
}

final class InvokerNullableReturnController
{
    public function show(ServerRequestInterface $request): ?ResponseInterface
    {
        return Responses::text('x');
    }
}

final class InvokerUntypedParamController
{
    public function show($request): ResponseInterface
    {
        return Responses::text('x');
    }
}

final class InvokerBuiltinParamController
{
    public function show(int $id): ResponseInterface
    {
        return Responses::text('x');
    }
}

final class InvokerDefaultParamController
{
    public function show(?RouteArgs $args = null): ResponseInterface
    {
        return Responses::text('x');
    }
}

final class InvokerVariadicController
{
    public function show(RouteArgs ...$args): ResponseInterface
    {
        return Responses::text('x');
    }
}

final class InvokerUnionController
{
    public function show(ServerRequestInterface|RouteArgs $either): ResponseInterface
    {
        return Responses::text('x');
    }
}

final class InvokerUnregisteredServiceController
{
    public function show(InvokerMissingService $service): ResponseInterface
    {
        return Responses::text('x');
    }
}

function invoker_test_function(ServerRequestInterface $request): ResponseInterface
{
    return Responses::text('fn');
}