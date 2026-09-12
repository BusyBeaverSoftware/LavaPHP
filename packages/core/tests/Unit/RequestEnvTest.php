<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Container\Container;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\Severity;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The environment a problem page renders in comes from the request.
 *
 * The documented validation pattern — `HttpErrors::forReport($input->report(),
 * $request)` — is written in a handler with no easy way to learn the
 * environment, and it used to default to `dev`: a browser in production got the
 * verbose page, context and all. `App::handle()` now records the environment on
 * every request, and a render given no environment reads it.
 */
final class RequestEnvTest extends TestCase
{
    public function testTheAppRecordsItsEnvironmentOnEveryRequestItHandles(): void
    {
        // ok-app's config/.env says prod; the real environment wins, so a value
        // of `dev` here can only have come from the app's resolved environment.
        $booted = TestApp::bootFixture('ok-app', ['LAVA_ENV' => 'dev']);
        self::assertInstanceOf(App::class, $booted);

        $seen = new \ArrayObject();
        $container = new Container();
        $container->value('app.env_probe', new class ($seen) implements MiddlewareInterface {
            /** @param \ArrayObject<int, mixed> $seen */
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->seen[] = $request->getAttribute(HttpErrors::ENV_ATTRIBUTE);

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
            ['app.env_probe'],
        );
        $client = new TestClient($app);

        $client->get('/health'); // a route
        $client->get('/nope');   // and a request no route answers

        self::assertSame(['dev', 'dev'], $seen->getArrayCopy());
    }

    public function testARenderGivenNoEnvironmentReadsTheOneTheRequestCarries(): void
    {
        $browser = new ServerRequest('GET', '/', ['Accept' => 'text/html']);

        $inProd = self::body(HttpErrors::forReport(self::report(), $browser->withAttribute(HttpErrors::ENV_ATTRIBUTE, 'prod')));
        $inDev = self::body(HttpErrors::forReport(self::report(), $browser->withAttribute(HttpErrors::ENV_ATTRIBUTE, 'dev')));

        self::assertStringNotContainsString('context_only_detail', $inProd);
        self::assertStringContainsString('context_only_detail', $inDev);
    }

    public function testWithNoEnvironmentAnywhereThePageIsTheProductionOne(): void
    {
        $page = self::body(HttpErrors::forReport(self::report(), new ServerRequest('GET', '/', ['Accept' => 'text/html'])));

        self::assertStringNotContainsString('context_only_detail', $page);
        self::assertStringContainsString('FIX', $page);
    }

    public function testAnEnvironmentPassedExplicitlyStillWins(): void
    {
        $request = (new ServerRequest('GET', '/', ['Accept' => 'text/html']))
            ->withAttribute(HttpErrors::ENV_ATTRIBUTE, 'prod');

        self::assertStringContainsString('context_only_detail', self::body(HttpErrors::forReport(self::report(), $request, 'dev')));
    }

    private static function report(): ProblemReport
    {
        $report = new ProblemReport();
        $report->add(new class ('A field was refused.', 'Send a better one.', ['context_only_detail' => 'value']) extends LavaProblem {
            public function code(): string
            {
                return 'test_request_env';
            }

            public function severity(): Severity
            {
                return Severity::Fatal;
            }
        });

        return $report;
    }

    private static function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
