<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use App\RecordingLogger;
use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Container\Container;
use Lava\Core\Features\FlagSubjectResolver;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Http\Responses;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\RouteNotFound;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * What a request does when something on its path fails in a way no problem
 * describes, and what production shows of it.
 *
 * Found by an outside build (Lava Notes, B3/B4/B6): a handler's
 * `RuntimeException` left `App::handle()` as PHP's uncaught exception — an empty
 * 500, or with `display_errors` on a 200 carrying the stack trace — and a
 * production JSON error carried its context to any client that sent no Accept
 * header. The fixture's failing handler throws a message with a credential in
 * it, because that is where a real driver puts one.
 */
final class RequestFailureTest extends TestCase
{
    public function testAHandlerCanTypeHintTheUrlGeneratorItself(): void
    {
        // The plan is built at boot. It used to be built before BuildRouter
        // registered UrlGenerator, so this handler failed the boot with a
        // service_not_registered whose fix was a duplicate_service.
        $response = (new TestClient(self::boot('dev')))->get('/links/7');

        self::assertSame(200, $response->status());
        self::assertSame(['self' => '/links/7'], $response->json());
    }

    public function testAThrowableFromAHandlerIsAnUnexpectedFailureInTheAppsOwnMedia(): void
    {
        $response = (new TestClient(self::boot('dev')))->get('/boom');

        self::assertSame(500, $response->status());
        $problem = self::firstProblem($response->json());
        self::assertSame('unexpected_failure', $problem['code']);
        self::assertSame('GET', $problem['context']['method']);
        self::assertSame('/boom', $problem['context']['path']);
        self::assertSame(\RuntimeException::class, $problem['context']['exception']);
        // Outside production the response is the whole diagnosis.
        self::assertStringContainsString('hunter2', $problem['context']['message']);
    }

    public function testInProductionTheResponseWithholdsTheDetailsAndTheLogKeepsThem(): void
    {
        $app = self::boot('prod');
        $client = new TestClient($app);

        $json = $client->get('/boom');
        self::assertSame(500, $json->status());
        self::assertStringNotContainsString('hunter2', $json->body());
        // The keys stay, so a client parses one shape in every environment —
        // and the context is an empty OBJECT, as the problem schema requires.
        self::assertStringContainsString('"context":{}', $json->body());
        $problem = self::firstProblem($json->json());
        self::assertSame('unexpected_failure', $problem['code']);
        self::assertNull($problem['source']);

        $page = $client->get('/boom', ['Accept' => 'text/html']);
        self::assertSame(500, $page->status());
        self::assertStringNotContainsString('hunter2', $page->body());
        self::assertStringNotContainsString($app->appDir, $page->body());

        // Withheld from the client is not lost: the app's own logger has both.
        $logger = $app->container->get(LoggerInterface::class);
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertCount(2, $logger->entries);
        self::assertSame('error', $logger->entries[0]['level']);
        self::assertSame('unexpected_failure', $logger->entries[0]['context']['code']);
        self::assertStringContainsString('hunter2', $logger->entries[0]['context']['context']['message']);
    }

    public function testAGlobalMiddlewareMeetsTheThrowableBeforeTheAppDoes(): void
    {
        // Caught outside the pipeline, not inside it: an app's own error page is
        // a global middleware, and it must still be able to answer.
        $booted = self::boot('prod');
        $container = new Container();
        $container->value('app.catch_all', new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                try {
                    return $handler->handle($request);
                } catch (\RuntimeException) {
                    return Responses::json(['answered_by' => 'the app'], 503);
                }
            }
        });

        $response = (new TestClient(self::rebuilt($booted, $container, ['app.catch_all'])))->get('/boom');

        self::assertSame(503, $response->status());
        self::assertSame(['answered_by' => 'the app'], $response->json());
    }

    public function testAMiddlewareThatThrowsOnAnUnroutedRequestIsAnUnexpectedFailureToo(): void
    {
        $booted = self::boot('dev');
        $container = new Container();
        $container->value('app.broken', new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \LogicException('the middleware itself is broken');
            }
        });

        $response = (new TestClient(self::rebuilt($booted, $container, ['app.broken'])))->get('/no-such-page');

        self::assertSame(500, $response->status());
        $problem = self::firstProblem($response->json());
        self::assertSame('unexpected_failure', $problem['code']);
        self::assertSame(\LogicException::class, $problem['context']['exception']);
    }

    public function testASubjectResolverWhoseFactoryThrowsIsAnUnexpectedFailureToo(): void
    {
        // Lava Notes (R2-B5): the guard covered subjectFor() but not building
        // the resolver, and a `factory` is built again on every request — so
        // the boot sweep cannot vouch for it.
        $booted = self::boot('dev');
        $container = new Container();
        $container->factory(
            FlagSubjectResolver::class,
            static fn (): FlagSubjectResolver => throw new \RuntimeException('session store unavailable'),
        );

        $response = (new TestClient(self::rebuilt($booted, $container, [])))->get('/boom');

        self::assertSame(500, $response->status());
        $problem = self::firstProblem($response->json());
        self::assertSame('unexpected_failure', $problem['code']);
        self::assertSame('session store unavailable', $problem['context']['message']);
    }

    public function testASubjectResolverWhoseFactoryThrowsAProblemAnswersWithThatProblem(): void
    {
        $booted = self::boot('dev');
        $container = new Container();
        $container->factory(
            FlagSubjectResolver::class,
            static fn (): FlagSubjectResolver => throw RouteNotFound::of('GET', '/from-the-factory'),
        );

        $response = (new TestClient(self::rebuilt($booted, $container, [])))->get('/boom');

        self::assertSame(404, $response->status());
        self::assertSame('/from-the-factory', self::firstProblem($response->json())['context']['path']);
    }

    public function testAMessageThatIsNotUtf8StillRendersAsJson(): void
    {
        // Lava Notes (R2-B4): an upload's name reached an exception message
        // byte for byte, and encoding the problem threw a JsonException that
        // left handle() — an empty 500 in dev and test, hiding the original.
        $response = (new TestClient($this->throwingMiddleware(new \RuntimeException("could not read upload \xff\xfe.bin"))))
            ->get('/boom');

        self::assertSame(500, $response->status());
        $problem = self::firstProblem($response->json());
        self::assertSame('unexpected_failure', $problem['code']);
        self::assertIsString($problem['context']['message']);
        self::assertStringStartsWith('could not read upload ', $problem['context']['message']);
        self::assertStringContainsString("\u{FFFD}", $problem['context']['message']);
    }

    public function testAProblemThatCannotBeRenderedStillAnswersA500(): void
    {
        // The last resort: nothing a problem carries may turn the answer into
        // an exception. NAN has no JSON form, whatever the flags.
        $problem = new InvalidConfig('A ratio went wrong.', 'Fix the ratio.', ['ratio' => NAN]);

        $response = (new TestClient($this->throwingMiddleware($problem)))->get('/boom');

        self::assertSame(500, $response->status());
        self::assertStringStartsWith('text/plain', $response->header('Content-Type'));
        self::assertStringContainsString('invalid_config', $response->body());
    }

    public function testAClientMistakeKeepsItsContextInProduction(): void
    {
        // A 4xx is the caller's to fix, and the context is how an agent fixes it.
        $request = (new ServerRequest('GET', '/nope'))->withAttribute(HttpErrors::ENV_ATTRIBUTE, 'prod');
        $response = HttpErrors::toResponse(RouteNotFound::of('GET', '/nope'), $request);

        $problem = self::firstProblem(json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['method' => 'GET', 'path' => '/nope'], $problem['context']);
    }

    public function testOnlyAServerFaultInProductionIsRedacted(): void
    {
        self::assertTrue(HttpErrors::redacts(500, 'prod'));
        self::assertTrue(HttpErrors::redacts(503, 'prod'));
        self::assertFalse(HttpErrors::redacts(422, 'prod'));
        self::assertFalse(HttpErrors::redacts(404, 'prod'));
        self::assertFalse(HttpErrors::redacts(500, 'dev'));
        self::assertFalse(HttpErrors::redacts(500, 'staging'));
    }

    private static function boot(string $env): App
    {
        $app = TestApp::bootFixture('handler-app', ['LAVA_ENV' => $env]);
        self::assertInstanceOf(
            App::class,
            $app,
            $app instanceof BootFailure ? json_encode($app->problems->json(), JSON_PRETTY_PRINT) ?: '' : '',
        );

        return $app;
    }

    /** The fixture app in dev, behind one global middleware that throws this. */
    private function throwingMiddleware(\Throwable $throwable): App
    {
        $container = new Container();
        $container->value('app.throws', new readonly class ($throwable) implements MiddlewareInterface {
            public function __construct(private \Throwable $throwable)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw $this->throwable;
            }
        });

        return self::rebuilt(self::boot('dev'), $container, ['app.throws']);
    }

    /** @param list<string> $middleware */
    private static function rebuilt(App $booted, Container $container, array $middleware): App
    {
        return new App(
            $booted->appDir,
            $booted->env,
            $booted->config,
            $booted->features,
            $container,
            $booted->problems,
            $booted->router,
            $middleware,
        );
    }

    /** @return array<string, mixed> */
    private static function firstProblem(mixed $body): array
    {
        self::assertIsArray($body);
        self::assertIsArray($body['problems'] ?? null);
        self::assertIsArray($body['problems'][0] ?? null);

        return $body['problems'][0];
    }
}
