<?php

declare(strict_types=1);

namespace Lava\Core\Map;

/**
 * `lavaphp/core`'s public surface: what an app calls, and what it does not.
 *
 * Three directories are ruled out wholesale, each because something else in the
 * repository already owns that list; a class in a directory that is otherwise
 * API carries `@internal` on itself; and one class inside an excluded directory
 * is named an extension point, because `Console/Commands/` holds both the
 * concrete commands `lava list` owns and the base class an app extends.
 */
final class CoreApiSurface extends ApiSurface
{
    public function pack(): string
    {
        return 'core';
    }

    public function package(): string
    {
        return 'lavaphp/core';
    }

    public function namespacePrefix(): string
    {
        return 'Lava\\Core\\';
    }

    public function sourceRoot(): string
    {
        // This file sits at src/Map/, so src/ is one directory up. Derived
        // rather than configured, so the index follows the package wherever it
        // is installed — vendor/lavaphp/core in an app, packages/core in this
        // repository.
        return dirname(__DIR__);
    }

    public function groups(): array
    {
        return [
            'Boot' => 'the app object a handler is given, the boot context, and the runtime facts a status page reads',
            'Clock' => 'the clock services take, so a test can freeze time',
            'Config' => 'config files as typed values, and the env vars an app declares',
            'Console' => 'the CLI a pack extends: a command, its arguments, its two output views',
            'Container' => 'the container an app wires its services on',
            'Features' => 'feature flags: defining them, setting them, resolving them per subject',
            'Http' => 'responses, the problem-to-response mapping, and request body parsing',
            'Log' => 'the default logger, replaceable by registering the PSR id',
            'Map' => 'the generated project map, the framework reference, and this index',
            'Modules' => 'what a pack implements to be loaded, and the optional capabilities it may add',
            'Routing' => 'routes, their params, and URL generation',
            'Testing' => 'booting an app in a test and sending requests to it',
        ];
    }

    public function exclusions(): array
    {
        return [
            'Problem/' => 'every problem is catalogued in docs/problem-codes.md, with its code, the class, when it is raised and the fix — a second list here would be the one that rots',
            'Boot/Steps/' => 'boot steps run in a fixed order chosen by the kernel; nothing an app writes calls one',
            'Console/Commands/' => '`lava list` names every command with its flags and the schema its envelope claims',
        ];
    }

    public function extensionPoints(): array
    {
        return [
            \Lava\Core\Console\Commands\AppCommand::class => 'app/Commands.php extends it, and the generated AGENTS.md says so; `lava list` names the commands that exist, not the class you write one from',
        ];
    }

    public function examples(): array
    {
        return [
            \Lava\Core\Routing\Router::class => <<<'PHP'
                // app/Routes.php — every address this app answers, in one file.
                use Lava\Core\Routing\Router;

                return function (Router $r): void {
                    $r->pattern('slug', '[a-z0-9-]+');
                    $r->get('/posts/{slug:slug}', 'posts.show')->handler([App\Http\Posts::class, 'show']);
                    $r->post('/posts', 'posts.store')->handler([App\Http\Posts::class, 'store']);
                    $r->redirect('/p/{slug:slug}', 'posts.short', to: 'posts.show');
                };
                PHP,

            \Lava\Core\Routing\RouteBuilder::class => <<<'PHP'
                // Every registration returns a Lava\Core\Routing\RouteBuilder to chain on.
                use Lava\Core\Routing\Router;

                return function (Router $r): void {
                    $r->get('/admin', 'admin.home')
                        ->handler([App\Http\Admin::class, 'home'])
                        ->middleware(App\Http\RequireLogin::class)
                        ->when('admin_ui');
                };
                PHP,

            \Lava\Core\Container\Container::class => <<<'PHP'
                // app/Services.php — no auto-wiring: every id is built by visible code.
                use Lava\Core\Boot\AppContext;
                use Lava\Core\Container\Container;

                return function (Container $c, AppContext $ctx): void {
                    $c->singleton(App\Greeter::class, fn (Container $c): App\Greeter => new App\Greeter($ctx->env));
                    $c->value('greeting.name', 'Lava');
                    $c->alias(App\GreeterInterface::class, App\Greeter::class);
                };
                PHP,

            \Lava\Core\Http\Responses::class => <<<'PHP'
                use Lava\Core\Http\Responses;

                // A handler returns a PSR-7 response; these build the common ones.
                $created = Responses::json(['id' => 7], 201);
                $page = Responses::html('<h1>Hello</h1>');
                $note = Responses::text('queued');
                $away = Responses::redirect('/posts');
                $nothing = Responses::noContent();
                PHP,

            \Lava\Core\Routing\RouteArgs::class => <<<'PHP'
                use Lava\Core\Http\Responses;
                use Lava\Core\Routing\RouteArgs;
                use Psr\Http\Message\ResponseInterface;
                use Psr\Http\Message\ServerRequestInterface;

                function show_post(RouteArgs $args): ResponseInterface
                {
                    // Typed accessors: a route param is never a raw string to guess at.
                    return Responses::json(['id' => $args->int('id'), 'slug' => $args->str('slug')]);
                }

                function in_middleware(ServerRequestInterface $request): ?RouteArgs
                {
                    // Middleware runs before the handler, so it asks the request.
                    return RouteArgs::of($request);
                }
                PHP,

            \Lava\Core\Features\Features::class => <<<'PHP'
                use Lava\Core\Features\Features;

                function banner(Features $features): string
                {
                    // Resolved for this request's subject; an undefined name is fatal,
                    // never a silent false.
                    return $features->on('beta_ui') ? 'the new dashboard' : 'the old one';
                }
                PHP,

            \Lava\Core\Features\Flag::class => <<<'PHP'
                // config/features.php — the code default, then what this deployment sets.
                use Lava\Core\Features\Feature;
                use Lava\Core\Features\Flag;

                return [
                    'define' => [
                        Feature::define('beta_ui', Flag::rollout(25), description: 'The new dashboard'),
                    ],
                    'set' => [
                        'beta_ui' => Flag::env(['dev' => Flag::on(), 'prod' => Flag::off()]),
                    ],
                ];
                PHP,

            \Lava\Core\Config\EnvVar::class => <<<'PHP'
                // In app/Services.php: the variables this app reads, declared so
                // `lava env` can report a missing one before a request meets it.
                use Lava\Core\Config\EnvVar;
                use Lava\Core\Container\Container;

                return function (Container $c): void {
                    $c->value(EnvVar::CONTAINER_ID, [
                        EnvVar::required('DATABASE_DSN', 'Where the database lives.', secret: true),
                        EnvVar::optional('MAIL_FROM', 'The address outgoing mail comes from.'),
                    ]);
                };
                PHP,

            \Lava\Core\Modules\ModuleRef::class => <<<'PHP'
                // app/Modules.php — one line per pack, each gated by a feature.
                use Lava\Core\Modules\ModuleRef;

                return [
                    ModuleRef::of(Lava\Db\DbModule::class, package: 'lavaphp/db', feature: 'db'),
                ];
                PHP,

            \Lava\Core\Console\CommandRegistry::class => <<<'PHP'
                // app/Commands.php — the app's own CLI commands.
                use Lava\Core\Console\CommandRegistry;

                return function (CommandRegistry $registry): void {
                    $registry->add(new App\Console\PublishDueCommand());
                };
                PHP,

            \Lava\Core\Testing\TestApp::class => <<<'PHP'
                use Lava\Core\Boot\App;
                use Lava\Core\Testing\TestApp;

                $app = TestApp::boot(dirname(__DIR__), ['LAVA_ENV' => 'test']);
                // A boot that failed is a BootFailure carrying every problem, so a
                // test asserts on the app rather than on an exception.
                self::assertInstanceOf(App::class, $app);
                PHP,

            \Lava\Core\Testing\TestClient::class => <<<'PHP'
                use Lava\Core\Testing\TestApp;
                use Lava\Core\Testing\TestClient;

                $client = new TestClient(TestApp::boot(dirname(__DIR__)));
                $response = $client->get('/posts/hello');
                self::assertSame(200, $response->status());
                self::assertSame(['slug' => 'hello'], $response->json());
                PHP,
        ];
    }
}
