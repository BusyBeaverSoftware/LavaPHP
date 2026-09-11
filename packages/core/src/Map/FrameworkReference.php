<?php

declare(strict_types=1);

namespace Lava\Core\Map;

/**
 * The canonical minimal form of every artifact an app author writes.
 *
 * This is the "Framework Reference" half of AGENTS.md (plan R1), and it exists
 * so that ONE file teaches both this app and the framework. An agent that has
 * only ever seen this app can read the reference and write a correct route,
 * handler, middleware, or service registration without opening any other
 * documentation — which matters because the agent's alternative is guessing at
 * a DSL from the outside.
 *
 * **Why the snippets live here and not in a docs file.** AGENTS.md is generated,
 * so everything in it has a source. If that source were a hand-maintained
 * Markdown page, the reference would be exactly the kind of parallel doc the
 * framework bans everywhere else — and it would drift, silently, because nothing
 * would ever read it. Held as constants in core, the snippets are covered by
 * `tests/Unit/FrameworkReferenceTest.php`, which parses each PHP one and checks
 * that every class and method it names actually exists. A snippet that teaches a
 * method the framework does not have fails the build.
 *
 * What that test does NOT prove: that a snippet is a *complete working file*.
 * The examples are minimal on purpose — they show the shape, not the error
 * handling — so they are checked for truth, not for bootability. The `lava/app`
 * skeleton is the working-app proof, and `lava check` is what verifies it.
 */
final class FrameworkReference
{
    /**
     * Every artifact, in the order a reader builds an app: the three files that
     * declare things, the two that configure them, the classes those
     * declarations point at, then a test.
     *
     * `file` is the path relative to the app root, and it is the real path the
     * framework reads — `app/Routes.php` means `app/Routes.php`, not a
     * convention the reader has to confirm.
     *
     * `lang` is stated rather than sniffed: `config/.env` is not PHP, and a
     * fence that guessed from the content would eventually guess wrong.
     *
     * @return array<string, array{file: string, lang: string, note: string, code: string}>
     */
    public static function artifacts(): array
    {
        return [
            'routes' => [
                'file' => 'app/Routes.php',
                'lang' => 'php',
                'note' => 'Returns a callable that registers routes on the Router. A handler is '
                    . '`[Class::class, \'method\']` or a function name. A route with no `->when()` is '
                    . 'always active; a gated one is a real 404 when its flag is off.',
                'code' => <<<'PHP'
                    use Lava\Core\Routing\Router;

                    return function (Router $r): void {
                        // A custom param type, registered before it is used.
                        $r->pattern('word', '[a-z]+');

                        $r->get('/users/{id:int}', 'users.show')
                            ->handler([App\Http\UserController::class, 'show']);

                        $r->post('/greet/{name:word}', 'greet')
                            ->handler([App\Http\GreetController::class, 'greet'])
                            ->middleware(App\Http\AuthMiddleware::class)
                            ->when('greeting');
                    };
                    PHP,
            ],

            'handler' => [
                'file' => 'app/Http/UserController.php',
                'lang' => 'php',
                'note' => 'A handler is a class method (or a function). Its parameters are injected by '
                    . 'type: `ServerRequestInterface`, `RouteArgs` for the path parameters, or a '
                    . 'container id. The `: ResponseInterface` return type is required — boot checks it.',
                'code' => <<<'PHP'
                    namespace App\Http;

                    use Lava\Core\Http\Responses;
                    use Lava\Core\Routing\RouteArgs;
                    use Psr\Http\Message\ResponseInterface;
                    use Psr\Http\Message\ServerRequestInterface;

                    final class UserController
                    {
                        public function show(ServerRequestInterface $request, RouteArgs $args): ResponseInterface
                        {
                            return Responses::json(['id' => $args->int('id')]);
                        }
                    }
                    PHP,
            ],

            'middleware' => [
                'file' => 'app/Http/AuthMiddleware.php',
                'lang' => 'php',
                'note' => 'A PSR-15 middleware. It is resolved from the container, so register it in '
                    . '`app/Services.php` like any other service, then list it in `app/Middleware.php` '
                    . '(global) or on a route with `->middleware()`.',
                'code' => <<<'PHP'
                    namespace App\Http;

                    use Psr\Http\Message\ResponseInterface;
                    use Psr\Http\Message\ServerRequestInterface;
                    use Psr\Http\Server\MiddlewareInterface;
                    use Psr\Http\Server\RequestHandlerInterface;

                    final class AuthMiddleware implements MiddlewareInterface
                    {
                        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                        {
                            return $handler->handle($request);
                        }
                    }
                    PHP,
            ],

            'middleware-list' => [
                'file' => 'app/Middleware.php',
                'lang' => 'php',
                'note' => 'Returns the global middleware class-strings, outermost first. Each one wraps '
                    . 'every route — including the requests that end in a 404.',
                'code' => <<<'PHP'
                    return [
                        App\Http\TimingMiddleware::class,
                    ];
                    PHP,
            ],

            'services' => [
                'file' => 'app/Services.php',
                'lang' => 'php',
                'note' => 'Returns a callable that registers services on the Container. There is no '
                    . 'auto-wiring: every id is built by visible code, and registering one twice is '
                    . 'fatal. This is also where an app declares the environment variables it reads.',
                'code' => <<<'PHP'
                    use Lava\Core\Boot\AppContext;
                    use Lava\Core\Config\EnvVar;
                    use Lava\Core\Container\Container;

                    return function (Container $c, AppContext $ctx): void {
                        $c->singleton(App\Greeter::class, fn (Container $c): App\Greeter => new App\Greeter($ctx->env));
                        $c->value('greeting.name', 'Lava');

                        $c->value(EnvVar::CONTAINER_ID, [
                            EnvVar::required('DATABASE_URL', 'The database to connect to.', secret: true),
                        ]);
                    };
                    PHP,
            ],

            'modules' => [
                'file' => 'app/Modules.php',
                'lang' => 'php',
                'note' => 'Returns the packs this app loads, each gated by a feature. A module whose '
                    . 'feature is off is absent: its routes 404 and its commands do not exist. A pack '
                    . 'that is enabled but not installed is a boot problem naming the exact '
                    . '`composer require` to run.',
                'code' => <<<'PHP'
                    use Lava\Core\Modules\ModuleRef;

                    return [
                        ModuleRef::of(Lava\Db\DbModule::class, package: 'lava/db', feature: 'db'),
                    ];
                    PHP,
            ],

            'commands' => [
                'file' => 'app/Commands.php',
                'lang' => 'php',
                'note' => 'Returns a callable that registers the app\'s own commands. A name a core '
                    . 'command already uses is fatal — `lava routes` means the same thing in every app.',
                'code' => <<<'PHP'
                    use Lava\Core\Console\CommandRegistry;

                    return function (CommandRegistry $commands): void {
                        $commands->add(new App\Console\ReportCommand());
                    };
                    PHP,
            ],

            'features' => [
                'file' => 'config/features.php',
                'lang' => 'php',
                'note' => '`define` is the code default — the bottom of the resolution order. `set` is a '
                    . 'deployment override. A value in the real environment or in `config/.env` beats '
                    . 'both, which is what makes a flag turnable without a deploy.',
                'code' => <<<'PHP'
                    use Lava\Core\Features\Feature;
                    use Lava\Core\Features\Flag;

                    return [
                        'define' => [
                            Feature::define('beta_dashboard', Flag::rollout(50), description: 'The new dashboard'),
                        ],
                        'set' => [
                            'beta_dashboard' => Flag::on(),
                        ],
                    ];
                    PHP,
            ],

            'config' => [
                'file' => 'config/app.php',
                'lang' => 'php',
                'note' => 'Every file in `config/` is read as `<filename>.<key>`, so `base_url` here is '
                    . '`app.base_url` in code. Each value carries its provenance, which is what '
                    . '`lava config` reports.',
                'code' => <<<'PHP'
                    return [
                        'env' => 'dev',
                        'base_url' => 'http://localhost:8080',
                    ];
                    PHP,
            ],

            'env' => [
                'file' => 'config/.env',
                'lang' => 'text',
                'note' => 'Read at boot, and promoted into the process environment only for names the '
                    . 'real environment does not already define — a real env var always wins, so a '
                    . 'deployment never has to edit this file.',
                'code' => <<<'TEXT'
                    DATABASE_URL="sqlite:///var/app.sqlite"
                    LAVA_ENV=dev
                    TEXT,
            ],

            'test' => [
                'file' => 'tests/HealthTest.php',
                'lang' => 'php',
                'note' => 'The harness boots the app in-process — no server, no network — and dispatches '
                    . 'PSR-7 requests through the same handler the HTTP entry point uses.',
                'code' => <<<'PHP'
                    namespace App\Tests;

                    use Lava\Core\Boot\App;
                    use Lava\Core\Testing\TestApp;
                    use Lava\Core\Testing\TestClient;
                    use PHPUnit\Framework\TestCase;

                    final class HealthTest extends TestCase
                    {
                        public function testHealthIsOk(): void
                        {
                            $app = TestApp::boot(dirname(__DIR__));
                            self::assertInstanceOf(App::class, $app);

                            $response = (new TestClient($app))->get('/health');

                            self::assertSame(200, $response->status());
                        }
                    }
                    PHP,
            ],
        ];
    }

    /** The reference as the Markdown section AGENTS.md embeds. */
    public static function markdown(): string
    {
        $out = "## Framework reference\n\n"
            . "The canonical minimal form of every artifact you can write. Every file except\n"
            . "`public/index.php` is optional; `lava check` verifies the wiring between them, and\n"
            . "`lava map` regenerates this document when the app changes.\n";

        foreach (self::artifacts() as $artifact) {
            $out .= "\n### `{$artifact['file']}`\n\n"
                . wordwrap($artifact['note'], 78, "\n", false) . "\n\n"
                . "```{$artifact['lang']}\n"
                . $artifact['code'] . "\n"
                . "```\n";
        }

        return $out;
    }
}
