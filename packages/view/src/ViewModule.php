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
use Twig\Extension\ExtensionInterface;

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

        $namespaces = self::namespaces($ctx);
        $extensions = self::extensions($ctx);

        $container->singleton(
            ViewRenderer::class,
            static function (Container $c) use ($templateDir, $cacheDir, $debug, $namespaces, $extensions, $ctx): ViewRenderer {
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

                $twig = TwigFactory::of($templateDir, $cacheDir, $debug, $namespaces);
                foreach (ViewFunctions::registry($url, $scope) as $function) {
                    $twig->addFunction($function);
                }

                // Installed here, where the renderer is built, because Twig locks
                // its extension set at the first render, and ValidateWiring builds
                // this singleton at boot, before anything can render.
                foreach ($extensions as $id) {
                    if (!$c->has($id)) {
                        throw self::unregisteredExtension($id, $ctx->appDir);
                    }
                    $extension = $c->get($id);
                    if (!$extension instanceof ExtensionInterface) {
                        throw InvalidConfig::wrongService($id, ExtensionInterface::class, $extension);
                    }
                    $twig->addExtension($extension);
                }

                return new ViewRenderer($twig, $templateDir, $namespaces);
            },
        );
    }

    /**
     * `view.namespaces`: a Twig namespace => the directories `@namespace/…`
     * searches, first match first. `['paper' => ['themes/paper', 'views']]`
     * makes `@paper/layout.twig` the theme's own file when it has one and the
     * shared one when it does not: theme fallback, with no per-request state
     * and nothing added to the loader after boot.
     *
     * Checked here, at boot, like `view.path`: a missing directory would fail
     * every render that reaches it, and a name Twig cannot address would fail
     * them all without saying why.
     *
     * @return array<string, list<string>> absolute directories
     */
    private static function namespaces(AppContext $ctx): array
    {
        $namespaces = [];
        foreach ($ctx->config->array('view.namespaces', []) as $namespace => $dirs) {
            if (!is_string($namespace) || preg_match('/^[a-z][a-z0-9_]*$/D', $namespace) !== 1) {
                throw InvalidConfig::badType('view.namespaces', 'a map from namespace names (lowercase letters, digits and _) to directories', 'the key ' . var_export($namespace, true), 'config/view.php');
            }
            $list = is_string($dirs) ? [$dirs] : $dirs;
            if (!is_array($list) || $list === [] || !array_is_list($list)) {
                throw InvalidConfig::badType("view.namespaces.{$namespace}", 'a directory or a non-empty list of directories', get_debug_type($dirs), 'config/view.php');
            }
            foreach ($list as $dir) {
                if (!is_string($dir) || $dir === '') {
                    throw InvalidConfig::badType("view.namespaces.{$namespace}", 'a directory or a non-empty list of directories', 'a list holding ' . get_debug_type($dir), 'config/view.php');
                }
                $path = self::resolve($ctx->appDir, $dir);
                if (!is_dir($path)) {
                    throw ViewDirMissing::of($path, "view.namespaces.{$namespace}", $ctx->appDir);
                }
                $namespaces[$namespace][] = $path;
            }
        }

        return $namespaces;
    }

    /**
     * `view.extensions`: container ids of Twig extensions, installed when the
     * renderer is built. Each is registered in app/Services.php, which is how an
     * extension's own dependencies arrive and how `lava services` shows it.
     *
     * @return list<string>
     */
    private static function extensions(AppContext $ctx): array
    {
        $configured = $ctx->config->array('view.extensions', []);

        // The keys first, and named as keys: `['shout' => Shout::class]` and
        // `[1 => Shout::class]` were reported as "got string", the type of a
        // value that was fine (Lava Notes, R3-B14).
        foreach (array_keys($configured) as $position => $key) {
            if ($key !== $position) {
                throw InvalidConfig::badType(
                    'view.extensions',
                    'a list of distinct container ids',
                    is_string($key) ? "a map, with the key '{$key}'" : "the key {$key} where {$position} belongs",
                    'config/view.php',
                );
            }
        }

        $ids = [];
        foreach ($configured as $id) {
            if (!is_string($id) || $id === '' || in_array($id, $ids, true)) {
                throw InvalidConfig::badType(
                    'view.extensions',
                    'a list of distinct container ids',
                    is_string($id) && in_array($id, $ids, true) ? "'{$id}' twice" : get_debug_type($id),
                    'config/view.php',
                );
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * An id in `view.extensions` that nothing registers.
     *
     * The container's own `service_not_registered` names the renderer's factory,
     * in this file, as where the id was asked for, and says to remove the
     * reference there. The reader wrote the id in config/view.php, so that is
     * the file this names, as the source and in the fix, under the code
     * lava-view.md promises (Lava Notes, R3-B14). The two core classes are
     * written out rather than imported: an import line would move the factory's
     * line, which every committed map records.
     */
    private static function unregisteredExtension(string $id, string $appDir): \Lava\Core\Problem\ServiceNotRegistered
    {
        $file = $appDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'view.php';
        $problem = \Lava\Core\Problem\ServiceNotRegistered::of($id, $file, "listed in 'extensions' in config/view.php");
        $at = strrpos($id, '\\');
        $short = $at === false ? $id : substr($id, $at + 1);

        $fix = ($problem->context['type_exists'] ?? true) === false
            ? "If {$short} lives in another namespace, add its `use` import to config/view.php or write it fully qualified; "
                . "if it is your own extension, create it and register it in app/Services.php. Otherwise remove it from 'extensions' in config/view.php."
            : "Register it in app/Services.php, \$c->singleton({$id}::class, fn (Container \$c) => new {$id}(…)), "
                . "or remove it from 'extensions' in config/view.php.";

        return new \Lava\Core\Problem\ServiceNotRegistered($problem->getMessage(), $fix, $problem->context, \Lava\Core\Problem\SourceLocation::of($file, 1));
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
