<?php

declare(strict_types=1);

namespace Lava\Core\Map;

use Lava\Core\Boot\App;
use Lava\Core\Problem\StaleMap;

/**
 * The app as a document: everything an agent needs to work in this codebase,
 * compiled from the registries rather than re-derived from the files.
 *
 * **Compiled, not written.** Routes come from the Router, services from the
 * Container, flags from the Features registry, commands from the Command
 * Registry. There is no second copy of these facts anywhere — the framework's
 * "one source of truth per fact" law applied to documentation, and the reason
 * AGENTS.md cannot disagree with `lava routes`.
 *
 * **Environment-independent, deliberately.** A flag's *value* depends on the
 * environment; a flag's *existence* does not. This document lists declarations —
 * route paths, service ids, flag names, env var names — and never a resolved
 * state, so `lava map` writes the same bytes whatever `--env` says. That is what
 * makes the file safe to commit and the fingerprint meaningful: one that moved
 * with the environment would report a stale map on every deploy that changed
 * nothing.
 *
 * **No absolute paths.** Every path here is relative to the app root, so the
 * document is portable — copy the app to another machine and the map is still
 * accurate. (`source.file` in a *problem* is absolute for the opposite reason:
 * an agent has to be able to open it.)
 */
final readonly class ProjectMap
{
    /** The config files core itself reads — see LoadConfig and CollectFlagDefinitions. */
    private const CORE_CONFIG_FILES = ['config/app.php', 'config/features.php', 'config/logging.php'];

    /**
     * @param list<array{name: string, methods: list<string>, path: string, handler: string,
     *             feature: string|null, middleware: list<string>}> $routes
     * @param list<array{id: string, kind: string, target: string|null, class: string|null, at: string}> $services
     * @param list<array{name: string, default: string, pack: string|null, description: string}> $features
     * @param list<array{name: string, pack: string, summary: string}> $commands
     * @param list<string> $middleware
     * @param list<array{package: string, feature: string, module_class: string, declared_at: string,
     *             config_files: list<string>, env_vars: list<string>}> $modules
     * @param list<array{name: string, required: bool, secret: bool, declared_by: string|null,
     *             description: string}> $env
     * @param list<string> $config
     */
    private function __construct(
        public array $routes,
        public array $services,
        public array $features,
        public array $commands,
        public array $middleware,
        public array $modules,
        public array $env,
        public array $config,
    ) {
    }

    public static function of(App $app): self
    {
        $routes = [];
        foreach ($app->router->routes() as $route) {
            $routes[] = [
                'name' => $route->name,
                'methods' => $route->methodNames(),
                'path' => $route->path,
                'handler' => $app->router->plan($route->name)->describe(),
                'feature' => $route->feature,
                'middleware' => array_map(self::shorten(...), $route->middleware),
            ];
        }

        $services = [];
        foreach ($app->container->ids() as $id) {
            $record = $app->container->describe($id);
            // describe() follows aliases, so a returned id that differs from the
            // requested one means this id points at that target.
            $isAlias = $record->id !== $id;
            // Where this id was wired — an alias's own alias() call, not its
            // target's registration, which has a row of its own.
            $at = $app->container->declaredAt($id);
            $services[] = [
                'id' => $id,
                'kind' => $isAlias ? 'alias' : $record->kind->value,
                'target' => $isAlias ? $record->id : null,
                // The DECLARED type, never the class a resolution produced: a
                // factory that branches on the environment resolves to a
                // different class in each one, and a map built from that went
                // stale under every `--env` but the one it was written in.
                'class' => $isAlias ? null : $app->container->declaredType($id),
                'at' => self::relative($at->file, $app->appDir) . ':' . $at->line,
            ];
        }

        $features = [];
        foreach ($app->features->definitions->all() as $feature) {
            $features[] = [
                'name' => $feature->name,
                'default' => $feature->default->setting(),
                'pack' => $feature->pack,
                'description' => $feature->description,
            ];
        }

        $commands = [];
        foreach ($app->commands()->all() as $command) {
            $commands[] = [
                'name' => $command->name(),
                'pack' => $command->pack(),
                'summary' => $command->summary(),
            ];
        }

        $modules = [];
        foreach ($app->moduleRefs as $ref) {
            $manifest = $app->packs[$ref->moduleClass] ?? null;
            $modules[] = [
                'package' => $ref->package,
                'feature' => $ref->feature,
                'module_class' => self::shorten($ref->moduleClass),
                'declared_at' => self::relative($ref->declaredAt->file, $app->appDir) . ':' . $ref->declaredAt->line,
                // The manifest is null when a pack is enabled but not installed
                // — boot reports that, and the map still renders.
                'config_files' => $manifest->configFiles ?? [],
                'env_vars' => $manifest->envVars ?? [],
            ];
        }

        // Declared variables only. `App::envVars()` also lists names that exist
        // only in config/.env, because `lava env` is exactly where an undeclared
        // value has to be visible. But `.env` is one machine's gitignored file,
        // and a name found only there declares nothing about the app (decision
        // 42). Mapping it made a committed AGENTS.md stale on any machine whose
        // `.env` held a line nothing declared — `cp config/.env.example
        // config/.env`, the first step the demo documents, was enough.
        $env = [];
        foreach ($app->envVars() as $entry) {
            $var = $entry['var'];
            if ($var === null) {
                continue;
            }
            $env[] = [
                'name' => $entry['name'],
                'required' => $var->required,
                'secret' => $var->secret,
                'declared_by' => $entry['by'],
                'description' => $var->description,
            ];
        }

        return new self(
            $routes,
            $services,
            $features,
            $commands,
            array_map(self::shorten(...), $app->globalMiddleware),
            $modules,
            $env,
            self::configFiles($app),
        );
    }

    /**
     * The document's facts as plain arrays — what the fingerprint covers and
     * what `lava map --json` reports.
     *
     * @return array<string, mixed>
     */
    public function sections(): array
    {
        return [
            'routes' => $this->routes,
            'services' => $this->services,
            'features' => $this->features,
            'commands' => $this->commands,
            'middleware' => $this->middleware,
            'modules' => $this->modules,
            'env' => $this->env,
            'config' => $this->config,
        ];
    }

    /**
     * A short, stable digest of everything the document says.
     *
     * Hashing the FACTS rather than the rendered Markdown is the point: a change
     * to the renderer (a new column, different wording) must not make every
     * app's map stale, because nothing about those apps changed. Only a change
     * to what the app declares does — and then the file really is out of date.
     */
    public function fingerprint(): string
    {
        $canonical = json_encode($this->sections(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // 16 hex characters: long enough that a collision is not a practical
        // concern for a document hash, short enough to sit in a marker line a
        // reader will pass over without noticing.
        return substr(hash('sha256', $canonical), 0, 16);
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return [
            'routes' => count($this->routes),
            'services' => count($this->services),
            'features' => count($this->features),
            'commands' => count($this->commands),
            'middleware' => count($this->middleware),
            'modules' => count($this->modules),
            'env' => count($this->env),
        ];
    }

    /**
     * Whether a written document still describes this app — as the problem to
     * report, or null when it does.
     *
     * This lives here, on the compiler, rather than in each caller, because
     * `lava map --check` and `lava check` must give the SAME verdict. They are
     * two doors into one question, and a second copy of the comparison is how
     * two commands start disagreeing about whether an app's map is current.
     *
     * The verdict is computed from the map, never from the document's text: the
     * document is stale exactly when the app it should describe has changed.
     */
    public function staleness(MapDocument $document): ?StaleMap
    {
        $fingerprint = $this->fingerprint();
        if ($document->isFresh($fingerprint)) {
            return null;
        }
        if (!$document->exists()) {
            return StaleMap::missing($document->path);
        }

        // A file with no marker at all reports `stale` with a placeholder: it
        // exists and it is wrong, which is what `stale` says. Only an ABSENT
        // file is `missing`, and the fix is the same either way.
        return StaleMap::stale($document->path, $document->hash() ?? '(no marker)', $fingerprint);
    }

    /** The AGENTS.md body, without the fingerprint marker — MapDocument owns that. */
    public function markdown(): string
    {
        return "# AGENTS.md\n\n"
            . "This file is generated by `lava map` from the app's own registries. It describes\n"
            . "THIS app: its routes, services, flags, commands, and the environment variables it\n"
            . "reads. Do not edit it by hand — change the app and run `lava map` again.\n\n"
            . "Every path below is relative to the app root (the directory holding this file).\n\n"
            . "Verify the app in one command:\n\n"
            . "```sh\n"
            . "lava check          # boot, wiring, routes, features, env, commands, tests, and this file\n"
            . "lava check --quick  # boot's own findings only — no suite, no sweeps (under 2s)\n"
            . "lava map --check    # just this file: is it still an accurate map of the app?\n"
            . "```\n"
            . $this->packsSection()
            . $this->routesSection()
            . $this->servicesSection()
            . $this->featuresSection()
            . $this->commandsSection()
            . $this->envSection()
            . $this->middlewareSection()
            . $this->filesSection()
            . "\n" . FrameworkReference::markdown();
    }

    private function packsSection(): string
    {
        if ($this->modules === []) {
            return "\n## Packs\n\nThis app loads no packs. Add one in `app/Modules.php` to enable its\n"
                . "routes, services, and commands.\n";
        }

        $rows = [];
        foreach ($this->modules as $module) {
            $rows[] = [
                $module['package'],
                $module['feature'],
                $module['module_class'],
                $module['declared_at'],
            ];
        }

        return "\n## Packs\n\nEach entry is gated by its feature. A module whose flag is off is absent:\n"
            . "its routes 404 and its commands do not exist.\n\n"
            . self::table(['package', 'feature', 'module', 'declared at'], $rows);
    }

    private function routesSection(): string
    {
        $rows = [];
        foreach ($this->routes as $route) {
            $rows[] = [
                implode('|', $route['methods']),
                $route['path'],
                $route['name'],
                $route['handler'],
                $route['feature'] ?? '-',
                implode(' ', $route['middleware']) ?: '-',
            ];
        }

        return "\n## Routes (" . count($rows) . ")\n\n"
            . "Registration order is match order: the app's own routes are registered before a\n"
            . "pack's, so an app can override a pack route by claiming the same path.\n\n"
            . self::table(['methods', 'path', 'name', 'handler', 'gated by', 'middleware'], $rows);
    }

    private function servicesSection(): string
    {
        $rows = [];
        foreach ($this->services as $service) {
            $rows[] = [
                $service['id'],
                $service['kind'],
                $service['class'] ?? $service['target'] ?? '-',
                $service['at'],
            ];
        }

        return "\n## Services (" . count($rows) . ")\n\n"
            . "There is no auto-wiring: every id is built by the code at `wired at`. A handler can\n"
            . "type-hint any of these ids as a parameter.\n\n"
            . self::table(['id', 'kind', 'class', 'wired at'], $rows);
    }

    private function featuresSection(): string
    {
        $rows = [];
        foreach ($this->features as $feature) {
            $rows[] = [
                $feature['name'],
                $feature['default'],
                $feature['pack'] ?? '-',
                $feature['description'],
            ];
        }

        return "\n## Feature flags (" . count($rows) . ")\n\n"
            . "`default` is the code default, the bottom of the resolution order; the environment\n"
            . "and `config/.env` override it. `lava features resolve <flag>` shows which layer\n"
            . "decided. A flag gates a pack at boot and a route per request.\n\n"
            . self::table(['flag', 'default', 'pack', 'description'], $rows);
    }

    private function commandsSection(): string
    {
        $rows = [];
        foreach ($this->commands as $command) {
            $rows[] = [$command['name'], $command['pack'], $command['summary']];
        }

        return "\n## Commands (" . count($rows) . ")\n\n"
            . "Run `lava <name> --json` for the machine-readable form of any of these.\n\n"
            . self::table(['command', 'pack', 'summary'], $rows);
    }

    private function envSection(): string
    {
        $rows = [];
        foreach ($this->env as $var) {
            $rows[] = [
                $var['name'],
                $var['required'] ? 'required' : 'optional',
                $var['secret'] ? 'yes' : '-',
                $var['declared_by'] ?? '-',
                $var['description'],
            ];
        }

        return "\n## Environment variables (" . count($rows) . ")\n\n"
            . "A value in the real environment always beats `config/.env`. A required variable with\n"
            . "no value is a `lava check` warning, and a failure under `--strict`.\n\n"
            . self::table(['name', 'requirement', 'secret', 'declared by', 'purpose'], $rows);
    }

    private function middlewareSection(): string
    {
        if ($this->middleware === []) {
            return "\n## Global middleware\n\nNone. Add class-strings to `app/Middleware.php` to wrap every route.\n";
        }

        $rows = [];
        foreach ($this->middleware as $index => $class) {
            $rows[] = [(string) ($index + 1), $class];
        }

        return "\n## Global middleware (" . count($rows) . ")\n\n"
            . "Outermost first: each wraps the next, and the route handler is innermost.\n\n"
            . self::table(['#', 'middleware'], $rows);
    }

    private function filesSection(): string
    {
        $rows = [];
        foreach ($this->config as $file) {
            $rows[] = [$file];
        }

        return "\n## Files\n\n"
            . "The files this app has that the framework reads, every one optional. Config is\n"
            . "read only from `config/app.php`, `config/features.php`, `config/logging.php` and the\n"
            . "files an enabled pack declares; any other file in `config/` is not read.\n\n"
            . self::table(['file'], $rows);
    }

    /**
     * The config files this app has, from the filesystem and the pack manifests
     * rather than from what loaded.
     *
     * Reading it off `Config`'s provenance would be wrong here: a pack's config
     * file is only loaded when the pack's flag is on, so the list would change
     * with the environment and the fingerprint would move on a deploy that
     * changed nothing. A file that EXISTS is a fact; a file that was read is a
     * resolution.
     *
     * @return list<string>
     */
    private static function configFiles(App $app): array
    {
        $files = [
            'app/Modules.php',
            'app/Routes.php',
            'app/Services.php',
            'app/Middleware.php',
            'app/Commands.php',
            'config/.env',
        ];

        // Only config files something reads. Every `config/*.php` used to be
        // listed, under a heading that says the framework reads these paths — so
        // a config/cache.php nothing opens read as if it were loaded, the same
        // wrong promise the skeleton's comment made.
        foreach (self::CORE_CONFIG_FILES as $file) {
            if (is_file($app->appDir . '/' . $file)) {
                $files[] = $file;
            }
        }

        foreach ($app->packs as $manifest) {
            foreach ($manifest->configFiles as $name) {
                // A pack declares a config file as a STEM — `configFiles:
                // ['database']` — and core's LoadPackConfig reads
                // `config/{$name}.php`. Rendering the stem verbatim would put
                // `config/database` in the table, a path that is not a file,
                // next to the real `config/database.php` the glob above found:
                // the same file listed twice, once under a name that cannot be
                // opened. Spelling the extension here makes the two collapse.
                $files[] = 'config/' . $name . '.php';
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** `Lava\Core\Http\Responses` → `Responses`; an app or pack class keeps its name. */
    private static function shorten(string $class): string
    {
        return str_starts_with($class, 'Lava\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;
    }

    /**
     * A path in the document's portable form.
     *
     * Three cases, decided in this order:
     *
     *  1. **Under the app root** — the app's own files, so `app/Services.php` —
     *     unless the app installed a dependency into itself, which is still
     *     dependency code and case 2's business.
     *  2. **Dependency code** — anything under `vendor/<vendor>/<pkg>/` or
     *     `packages/<pkg>/` becomes `<pkg>:<rest>`, e.g. `db:src/DbModule.php`.
     *     This is the important one: an installed app holds core at
     *     `vendor/lavaphp/core/src/…` and a checkout booting it from a sibling
     *     directory holds it at `packages/core/src/…`. Both must render — and
     *     therefore HASH — identically, or an app's committed AGENTS.md would be
     *     stale the moment someone installed the same packages a different way.
     *  3. **Anything else** — a path outside the app that is recognisably
     *     neither, rendered as a `../` chain. Unreachable for an installed app,
     *     where every path the map renders is under `vendor/`; it exists so a map
     *     generated in an unusual layout still renders something rather than
     *     nothing.
     *
     * The app root is decided FIRST because it is the strongest context there
     * is, and the reason is not hypothetical: this repo's own fixture apps live
     * under `packages/core/tests/fixtures/apps/`, so a rule that looked for the
     * `packages` marker anywhere would render `app/Services.php` as
     * `core:tests/fixtures/apps/ok-app/app/Services.php` — and then the very
     * same app, booted from a temp copy, would hash differently.
     */
    public static function relative(string $path, string $appDir): string
    {
        // Trailing slashes are trimmed on both sides, so the app root given as
        // `/srv/site/` is the same place as `/srv/site` — otherwise the root
        // itself would render as the empty string.
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $appDir), '/');

        if ($path === $root) {
            return '.';
        }

        if (str_starts_with($path, $root . '/')) {
            $inside = substr($path, strlen($root) + 1);

            // Only the FIRST segment can turn the app's own tree into a
            // dependency's: `vendor/` and `packages/` are composer's layouts at
            // the app root. A deeper directory that happens to be called
            // `packages` is one the app named that, and its files are the app's.
            if (str_starts_with($inside, 'vendor/') || str_starts_with($inside, 'packages/')) {
                return self::dependencyPath($inside) ?? $inside;
            }

            return $inside;
        }

        return self::dependencyPath($path) ?? self::outsidePath($path, $root);
    }

    /**
     * `<pkg>:<rest>` when the path sits inside an installed or checked-out
     * package, or null when it does not.
     *
     * A leading `/` is added when there is none, so the marker may sit at the
     * very start: `vendor/lavaphp/core/src/X.php` is a real shape — it is exactly
     * what the remainder looks like once the app root has been stripped off.
     */
    private static function dependencyPath(string $path): ?string
    {
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // `.*` is greedy, and a longer prefix means a LATER marker, so the last
        // marker that still fits the layout wins: a package that vendors its own
        // dependencies nests a second `vendor/`, and the inner one names the
        // file's real owner. A lazy `.*` would pick the outer one every time.
        if (preg_match('#^.*/(?:vendor/[^/]+|packages)/([^/]+)/(.+)$#', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1] . ':' . $matches[2];
    }

    /**
     * A path outside the app root as a `../` chain — the last resort, for a
     * layout where nothing recognisable applies. Relative rather than absolute
     * for the same reason as everything else here: an absolute path would make
     * the document machine-specific, and its fingerprint machine-dependent.
     */
    private static function outsidePath(string $path, string $root): string
    {
        $from = explode('/', trim($root, '/'));
        $to = explode('/', trim($path, '/'));
        // Stop one short of the basename: a relative path that consumed it would
        // have nothing left to point at.
        $common = 0;
        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            $common++;
        }

        return str_repeat('../', count($from) - $common) . implode('/', array_slice($to, $common));
    }

    /**
     * A Markdown pipe table.
     *
     * Unpadded on purpose, unlike the CLI's `Table`: a Markdown table needs no
     * column alignment to render, and padding it would put trailing whitespace in
     * a file humans read and diff. Cells escape `|` so a value containing one
     * cannot change the table's shape.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private static function table(array $headers, array $rows): string
    {
        if ($rows === []) {
            return "_None._\n";
        }

        $lines = [self::row($headers)];
        $lines[] = '|' . implode('|', array_map(
            static fn (string $header): string => str_repeat('-', max(3, strlen($header))),
            $headers,
        )) . '|';
        foreach ($rows as $row) {
            $lines[] = self::row($row);
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $cells */
    private static function row(array $cells): string
    {
        return '| ' . implode(' | ', array_map(
            static fn (string $cell): string => str_replace('|', '\|', $cell),
            $cells,
        )) . ' |';
    }
}
