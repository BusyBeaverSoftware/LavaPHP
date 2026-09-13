<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Container\Container;
use Lava\Core\Routing\RouteArgs;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware learns which route matched (Lava Notes, R2-G1).
 *
 * The match used to reach only the handler, so a permission check keyed by
 * route needed one middleware class per requirement or a second match of the
 * path. `App` now records the match on the request before the first layer runs.
 */
final class RouteAttributeTest extends TestCase
{
    public function testTheOutermostLayerSeesTheMatchedRouteAndItsTypedParams(): void
    {
        $booted = TestApp::bootFixture('ok-app', ['LAVA_ENV' => 'dev']);
        self::assertInstanceOf(App::class, $booted);

        // A probe as the only global middleware, around a container that holds
        // nothing else: what it records is what the first layer is handed,
        // whatever the handler behind it then does.
        $seen = new \ArrayObject();
        $container = new Container();
        $container->value('app.route_probe', new class ($seen) implements MiddlewareInterface {
            /** @param \ArrayObject<int, RouteArgs|null> $seen */
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->seen[] = RouteArgs::of($request);

                return $handler->handle($request);
            }
        });
        $app = new App(
            $booted->appDir,
            $booted->env,
            $booted->config,
            $booted->features,
            $container,
            $booted->problems,
            $booted->router,
            ['app.route_probe'],
        );
        $client = new TestClient($app);

        $client->get('/health');
        $client->get('/greet/ada');
        $client->get('/nope');

        [$health, $greet, $none] = $seen->getArrayCopy();
        self::assertInstanceOf(RouteArgs::class, $health);
        self::assertSame('health', $health->routeName);
        self::assertSame([], $health->all());
        self::assertInstanceOf(RouteArgs::class, $greet);
        self::assertSame('greet', $greet->routeName);
        self::assertSame('ada', $greet->str('name'));
        self::assertNull($none, 'A request no route answered reaches global middleware with no route on it.');
    }

    public function testARequestThatNeverWentThroughTheAppHasNoRoute(): void
    {
        self::assertNull(RouteArgs::of(new ServerRequest('GET', '/greet/ada')));
        self::assertNull(RouteArgs::of((new ServerRequest('GET', '/'))->withAttribute(RouteArgs::ATTRIBUTE, 'not a match')));
    }
}
