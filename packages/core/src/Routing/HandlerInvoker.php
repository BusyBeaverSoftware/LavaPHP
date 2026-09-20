<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

use Lava\Core\Container\Container;
use Lava\Core\Problem\BadHandler;
use Lava\Core\Problem\ServiceNotRegistered;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds injection plans at boot (the sanctioned reflection: read-only,
 * signature only, never constructing anything) and invokes handlers at
 * runtime from the stored plan — no reflection after boot, ever.
 *
 * Handler contract, enforced at boot:
 *   - [ClassName::class, 'method'] or 'function_name';
 *   - the class has no required constructor parameters (dependencies arrive
 *     as typed method parameters — that is what makes the whole dependency
 *     story of a route visible in `lava routes --json`);
 *   - every parameter is typed exactly ServerRequestInterface/RequestInterface
 *     (the request), RouteArgs (the matched params), or a registered
 *     container id (a service). Scalars, unions, defaults, variadics: no;
 *   - the return type is declared and is a ResponseInterface.
 *
 * @internal a handler's injection plan, built at boot
 */
final class HandlerInvoker
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array{0: string, 1: string}|string $handler [ClassName::class, 'method'] or 'function_name'
     * @return HandlerPlan the validated, boot-frozen plan
     */
    public function plan(array|string $handler): HandlerPlan
    {
        if (is_array($handler)) {
            return $this->planMethod($handler);
        }
        return $this->planFunction($handler);
    }

    /** Dispatches a matched route's handler from its plan. */
    public function invoke(HandlerPlan $plan, ServerRequestInterface $request, RouteArgs $args): ResponseInterface
    {
        $values = [];
        foreach ($plan->injects as $entry) {
            $values[] = match ($entry['kind']) {
                'request' => $request,
                'args' => $args,
                'service' => $this->container->get($entry['type']),
            };
        }

        if ($plan->kind === 'method') {
            $class = (string) $plan->class;
            $method = $plan->function;
            $result = (new $class())->{$method}(...$values);
        } else {
            $function = $plan->function;
            // Checked at plan time; re-checked here because a function can only
            // be called if it still exists, and `is_callable` is what proves it.
            if (!is_callable($function)) {
                throw BadHandler::of(
                    $plan->describe(),
                    'the function is no longer defined at dispatch time',
                    'Require its file in app/Routes.php (or composer.json autoload.files) so it exists for the whole request.',
                );
            }
            $result = $function(...$values);
        }

        if (!$result instanceof ResponseInterface) {
            throw BadHandler::of(
                $plan->describe(),
                'returned ' . get_debug_type($result) . ' instead of a ResponseInterface',
                'Return Responses::json(…)/text(…)/html(…) — and declare : ResponseInterface on the handler'
                . ' so this is caught at boot instead of mid-request.',
            );
        }
        return $result;
    }

    /**
     * @param array{0: string, 1: string}|array<mixed> $handler
     */
    private function planMethod(array $handler): HandlerPlan
    {
        if (count($handler) !== 2 || !isset($handler[0], $handler[1]) || !is_string($handler[0]) || !is_string($handler[1])) {
            throw BadHandler::of(
                json_encode($handler) ?: 'array handler',
                'array handlers must be [ClassName::class, \'method\']',
                "Write ->handler([\App\YourController::class, 'show']).",
            );
        }
        [$class, $method] = $handler;
        if (!class_exists($class)) {
            throw BadHandler::of(
                "{$class}::{$method}",
                'the class does not exist',
                'Check the class name, and that its package is installed and autoloaded.',
            );
        }
        $classReflection = new \ReflectionClass($class);
        if (!$classReflection->isInstantiable()) {
            throw BadHandler::of(
                "{$class}::{$method}",
                'the class cannot be instantiated (it is abstract or an enum)',
                'Handlers are constructed with new — use a concrete class.',
            );
        }
        $this->requireParameterlessConstructor($classReflection, "{$class}::{$method}");
        if (!method_exists($class, $method)) {
            throw BadHandler::of(
                "{$class}::{$method}",
                'the method does not exist',
                "Fix the method name, or add it: public function {$method}(…): ResponseInterface.",
            );
        }
        $reflection = new \ReflectionMethod($class, $method);
        if (!$reflection->isPublic()) {
            throw BadHandler::of("{$class}::{$method}", 'the method is not public', 'Make it public.');
        }

        return $this->finishPlan('method', $class, $method, $reflection);
    }

    private function planFunction(string $function): HandlerPlan
    {
        if (!function_exists($function)) {
            throw BadHandler::of(
                "'{$function}'",
                'the function is not defined',
                'Define it and make sure it is loaded — require its file in app/Routes.php'
                . ' or list the file in composer.json autoload.files.',
            );
        }
        $reflection = new \ReflectionFunction($function);

        return $this->finishPlan('function', null, $function, $reflection);
    }

    /** @param \ReflectionClass<object> $class */
    private function requireParameterlessConstructor(\ReflectionClass $class, string $describe): void
    {
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return;
        }
        foreach ($constructor->getParameters() as $param) {
            if (!$param->isOptional()) {
                throw BadHandler::of(
                    $describe,
                    'the constructor has required parameters',
                    'Handlers are constructed with no arguments — take dependencies as typed method'
                    . ' parameters (they appear in the route\'s injection plan), not constructor arguments.',
                );
            }
        }
    }

    /**
     * @param \ReflectionMethod|\ReflectionFunction $reflection
     */
    private function finishPlan(string $kind, ?string $class, string $function, \ReflectionMethod|\ReflectionFunction $reflection): HandlerPlan
    {
        $describe = $class !== null ? "{$class}::{$function}" : "'{$function}'";

        $returnType = $reflection->getReturnType();
        if ($returnType === null) {
            throw BadHandler::of(
                $describe,
                'no return type is declared',
                "Declare it: {$describe}(…): \Psr\Http\Message\ResponseInterface.",
            );
        }
        if ($returnType->allowsNull()) {
            throw BadHandler::of(
                $describe,
                'declares a nullable return type',
                'Handlers always return a response — declare \Psr\Http\Message\ResponseInterface without the ?.',
            );
        }
        if (!$returnType instanceof \ReflectionNamedType
            || !is_a($returnType->getName(), ResponseInterface::class, true)) {
            $declared = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : '(union)';
            throw BadHandler::of(
                $describe,
                "declares return type '{$declared}'",
                'Handlers must declare and return \Psr\Http\Message\ResponseInterface'
                . ' — build it with Responses::json(…)/text(…)/html(…).',
            );
        }

        /** @var list<array{kind: 'request'|'args'|'service', type: string, name: string}> $injects */
        $injects = [];
        foreach ($reflection->getParameters() as $param) {
            $injects[] = $this->planParameter($describe, $param);
        }

        return new HandlerPlan(
            $kind,
            $class,
            $function,
            $reflection->getFileName() ?: null,
            $reflection->getStartLine() ?: null,
            $injects,
        );
    }

    /**
     * Registered ids whose last segment is this type's — what an unqualified
     * parameter type with a missing `use` import almost always meant.
     *
     * @return list<string>
     */
    private function sameShortName(string $type): array
    {
        $short = strtolower(self::lastSegment($type));
        $matches = [];
        foreach ($this->container->ids() as $id) {
            if ($id !== $type && strtolower(self::lastSegment($id)) === $short) {
                $matches[] = $id;
            }
        }

        return $matches;
    }

    private static function lastSegment(string $name): string
    {
        $at = strrpos($name, '\\');

        return $at === false ? $name : substr($name, $at + 1);
    }

    /** @return array{kind: 'request'|'args'|'service', type: string, name: string} */
    private function planParameter(string $describe, \ReflectionParameter $param): array
    {
        $name = $param->getName();
        $type = $param->getType();

        if ($param->isVariadic()) {
            throw BadHandler::of($describe, "variadic parameter '\${$name}' cannot be injected", 'Remove it — every parameter gets exactly one injected value.');
        }
        if ($param->isOptional()) {
            throw BadHandler::of($describe, "parameter '\${$name}' has a default value", 'Remove the default — every handler parameter is always injected, defaults are dead code.');
        }
        if ($type === null) {
            throw BadHandler::of($describe, "parameter '\${$name}' has no type", "Type it as \Psr\Http\Message\ServerRequestInterface, \Lava\Core\Routing\RouteArgs, or a registered container service.");
        }
        if (!$type instanceof \ReflectionNamedType) {
            throw BadHandler::of($describe, "parameter '\${$name}' has a union/intersection type", 'Pick one injectable type: the request, RouteArgs, or a container service.');
        }

        $typeName = $type->getName();
        if ($typeName === ServerRequestInterface::class || $typeName === RequestInterface::class) {
            return ['kind' => 'request', 'type' => $typeName, 'name' => $name];
        }
        if ($typeName === RouteArgs::class) {
            return ['kind' => 'args', 'type' => $typeName, 'name' => $name];
        }
        if ($type->isBuiltin()) {
            throw BadHandler::of(
                $describe,
                "built-in parameter '\${$name}' ({$typeName}) cannot be injected",
                "Take \Lava\Core\Routing\RouteArgs and read the route param: \$args->{$typeName}('{$name}') — or type a container service.",
            );
        }
        if (!$this->container->has($typeName)) {
            throw ServiceNotRegistered::of(
                $typeName,
                $describe,
                'the handler needs it as an injected parameter',
                $this->sameShortName($typeName),
            );
        }
        return ['kind' => 'service', 'type' => $typeName, 'name' => $name];
    }
}