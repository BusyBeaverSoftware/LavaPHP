<?php

declare(strict_types=1);

namespace Lava\View;

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Lava\Core\Features\FeatureScope;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Routing\UrlGenerator;
use Lava\View\Problem\ViewDirMissing;

/**
 * lavaphp/view's entry point.
 *
 * One service, registered as a singleton: the {@see ViewRenderer}. Everything
 * else the pack ships is reached through it — the Twig environment through
 * `environment()`, the functions through the environment, the problems through
 * rendering. A pack whose whole surface is one id is a pack an agent can
 * understand from `lava services`.
 *
 * **`UrlGenerator` is resolved inside the factory, not passed to `register()`,
 * and the boot order is why.** `register()` runs in `WireModules`, which is
 * before `BuildRouter` — the router does not exist yet, so there is nothing to
 * hand over. The factory closure, on the other hand, is *called* by
 * `ValidateWiring`, which runs after `BuildRouter` has registered the
 * generator. So the dependency is declared as what it is (a container lookup)
 * and resolved at the moment it is resolvable. Nothing is lazy at request time:
 * the whole graph is built and checked during boot.
 *
 * That lookup is also why a broken `UrlGenerator` registration cannot reach a
 * request. If an app registered something else under that id, the check below
 * turns it into `invalid_config` naming the id and both types, at boot, instead
 * of an "undefined method url() on string" on whichever page renders a link
 * first.
 *
 * **The template directory is validated here rather than left to Twig.** A
 * missing directory is a configuration mistake that makes every render fail
 * identically, so it is worth one boot-time problem naming the path and the
 * config key. See {@see ViewDirMissing} for why leaving it to
 * `FilesystemLoader` produces a worse message.
 */
final class ViewModule implements Module
{
    /** Where templates live when config/view.php says nothing. */
    public const DEFAULT_PATH = 'views';

    /** Where compiled templates go when config/view.php says nothing. */
    public const DEFAULT_CACHE = 'var/views';

    public function pack(): PackInfo
    {
        // `views` is the feature name, matching the plan's Modules.php example.
        // It is the pack's gate and this file's declaration is the only place
        // it is defined — see CollectFlagDefinitions.
        return PackInfo::of('lavaphp/view', 'views', configFiles: ['view']);
    }

    public function register(Container $container, AppContext $ctx): void
    {
        $templateDir = self::resolve($ctx->appDir, $ctx->config->string('view.path', self::DEFAULT_PATH));

        // `debug` defaults to "not production" rather than to a constant, so an
        // app gets useful template errors in dev without configuring anything
        // and a template cache in production without remembering to turn one
        // on. LAVA_ENV is the same switch that decides the logger's level.
        $debug = $ctx->config->bool('view.debug', $ctx->env !== 'prod');

        // An empty `view.cache` means "compile every render", which is the
        // right answer for a read-only filesystem and a deliberate choice
        // rather than a mistake — so it is honoured, not corrected.
        $cacheDir = self::resolve($ctx->appDir, $ctx->config->string('view.cache', self::DEFAULT_CACHE));

        if (!is_dir($templateDir)) {
            throw ViewDirMissing::of($templateDir, 'view.path', $ctx->appDir);
        }

        $container->singleton(
            ViewRenderer::class,
            static function (Container $c) use ($templateDir, $cacheDir, $debug): ViewRenderer {
                $url = $c->get(UrlGenerator::class);
                if (!$url instanceof UrlGenerator) {
                    throw InvalidConfig::wrongService(UrlGenerator::class, UrlGenerator::class, $url);
                }

                // The scope, not a `Features`: the renderer is built once, and
                // `feature()` must answer for the request being rendered, which
                // only the scope knows at call time.
                $scope = $c->get(FeatureScope::class);
                if (!$scope instanceof FeatureScope) {
                    throw InvalidConfig::wrongService(FeatureScope::class, FeatureScope::class, $scope);
                }

                $twig = TwigFactory::of($templateDir, $cacheDir, $debug);
                foreach (ViewFunctions::registry($url, $scope) as $function) {
                    $twig->addFunction($function);
                }

                return new ViewRenderer($twig, $templateDir);
            },
        );
    }

    /**
     * A configured path, made absolute against the app directory.
     *
     * Relative values are resolved against the APP, never against the working
     * directory — the same rule lavaphp/db's `config/database.php` follows and for
     * the same reason: `lava serve` and a request under FPM have different
     * working directories, and a path that depends on which one is running is a
     * path that works in development and 404s in production.
     */
    private static function resolve(string $appDir, string $path): string
    {
        if ($path === '' || self::isAbsolute($path)) {
            return $path;
        }

        return $appDir . DIRECTORY_SEPARATOR . $path;
    }

    private static function isAbsolute(string $path): bool
    {
        // Unix, and the Windows drive-letter and UNC forms. Written out rather
        // than delegated so the rule is visible: only these two count as
        // absolute, and everything else is app-relative.
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
