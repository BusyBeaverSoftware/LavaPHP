<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Container\Container;
use Lava\Core\Http\Responses;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A request no handler answers — no route, the wrong method, a body that does
 * not parse — still passes through the app's global middleware.
 *
 * The layers here RECORD that they ran rather than stamping a header on the
 * way out, and that choice is the point of the test: the problem is thrown
 * through the layers, so a layer that only decorates the response it gets back
 * gets none — exactly as for a handler's problem. A header would be absent
 * whether or not the layer ran, and could not tell the two apart.
 */
final class UnroutedRequestTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        $app = TestApp::bootFixture('ok-app');
        self::assertInstanceOf(App::class, $app);
        self::$app = $app;
    }

    public function testAnUnknownPathPassesThroughGlobalMiddleware(): void
    {
        $log = new \ArrayObject();
        $container = new Container();
        $container->value('app.recorder', self::recorder($log, 'global'));

        $response = (new TestClient($this->appWith($container, ['app.recorder'])))->get('/nope');

        self::assertSame(404, $response->status());
        self::assertSame('route_not_found', $response->json()['problems'][0]['code']);
        self::assertSame(['global'], $log->getArrayCopy());
    }

    public function testTheWrongMethodRunsGlobalButNotRouteMiddleware(): void
    {
        // ok-app's `/users/{id:int}` declares `AuthMiddleware` as route
        // middleware, and the pipeline resolves that id from the container — so
        // a recorder registered under it runs wherever the route's own would.
        $log = new \ArrayObject();
        $container = new Container();
        $container->value('app.recorder', self::recorder($log, 'global'));
        $container->value(\App\Http\AuthMiddleware::class, self::recorder($log, 'route'));
        $client = new TestClient($this->appWith($container, ['app.recorder']));

        // The control: the route's method runs both layers, outermost first.
        self::assertSame(200, $client->get('/users/42')->status());
        self::assertSame(['global', 'route'], $log->getArrayCopy());

        $log->exchangeArray([]);
        $response = $client->post('/users/42');

        self::assertSame(405, $response->status());
        self::assertSame('method_not_allowed', $response->json()['problems'][0]['code']);
        self::assertSame('GET', $response->header('Allow'));
        // A POST matched no route, so nothing the route declared may run on it.
        self::assertSame(['global'], $log->getArrayCopy());
    }

    public function testABodyThatDoesNotParsePassesThroughGlobalMiddleware(): void
    {
        $log = new \ArrayObject();
        $container = new Container();
        $container->value('app.recorder', self::recorder($log, 'global'));

        $request = new ServerRequest('POST', '/health', ['Content-Type' => 'application/json']);
        $request->getBody()->write('{"title": ');
        $request->getBody()->rewind();

        $response = $this->appWith($container, ['app.recorder'])->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('malformed_body', $body['problems'][0]['code']);
        self::assertSame(['global'], $log->getArrayCopy());
    }

    public function testAGlobalLayerCanAnswerAnUnroutedRequestItself(): void
    {
        // The case the change exists for: an app's own not-found page. Before,
        // a mistyped URL reached a browser as the framework's diagnostics page
        // however the app was layered, because no layer ever saw the request.
        $container = new Container();
        $container->value('app.not_found_page', new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                try {
                    return $handler->handle($request);
                } catch (LavaProblem $problem) {
                    return Responses::html('<h1>Nothing here</h1>', $problem->httpStatus());
                }
            }
        });

        $response = (new TestClient($this->appWith($container, ['app.not_found_page'])))
            ->get('/no/such/page', ['Accept' => 'text/html']);

        self::assertSame(404, $response->status());
        self::assertSame('<h1>Nothing here</h1>', $response->body());
    }

    public function testALayerThatDoesNotCatchChangesNothing(): void
    {
        // ok-app as booted: its one global layer decorates the response it gets
        // back and catches nothing, so the problem escapes and renders as it
        // always did — no layer is forced to know about unrouted requests.
        $client = new TestClient(self::$app);

        $notFound = $client->get('/nope');
        self::assertSame(404, $notFound->status());
        self::assertSame('route_not_found', $notFound->json()['problems'][0]['code']);

        $notAllowed = $client->post('/users/42');
        self::assertSame(405, $notAllowed->status());
        self::assertSame('GET', $notAllowed->header('Allow'));
    }

    public function testWithNoGlobalMiddlewareTheAnswersAreUnchanged(): void
    {
        $client = new TestClient($this->appWith(new Container(), []));

        $notFound = $client->get('/nope');
        self::assertSame(404, $notFound->status());
        self::assertSame('route_not_found', $notFound->json()['problems'][0]['code']);

        $notAllowed = $client->post('/users/42');
        self::assertSame(405, $notAllowed->status());
        self::assertSame('GET', $notAllowed->header('Allow'));
    }

    /** @param \ArrayObject<int, string> $log */
    private static function recorder(\ArrayObject $log, string $name): MiddlewareInterface
    {
        return new class($log, $name) implements MiddlewareInterface {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly \ArrayObject $log, private readonly string $name)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->log[] = $this->name;
                return $handler->handle($request);
            }
        };
    }

    /** @param list<string> $globalMiddleware */
    private function appWith(Container $container, array $globalMiddleware): App
    {
        return new App(
            self::$app->appDir,
            self::$app->env,
            self::$app->config,
            self::$app->features,
            $container,
            self::$app->problems,
            self::$app->router,
            $globalMiddleware,
        );
    }
}
