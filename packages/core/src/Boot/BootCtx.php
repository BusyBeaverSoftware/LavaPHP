<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Problem\ProblemReport;

/**
 * The mutable accumulator steps share. Boot-only: everything here is
 * consumed into {@see App} (or {@see BootFailure}) when boot ends.
 */
final class BootCtx
{
    public ?\Lava\Core\Config\Config $config = null;
    public ?\Lava\Core\Features\FeatureSet $featureDefinitions = null;
    public ?\Lava\Core\Features\FeatureSettings $featureSettings = null;
    public ?\Lava\Core\Features\Features $features = null;
    public ?\Lava\Core\Container\Container $container = null;
    public ?AppContext $appContext = null;
    public ?\Lava\Core\Routing\Router $router = null;
    public string $env = 'dev';

    /**
     * Whether every installed pack counts as enabled, whatever its gate resolves
     * to.
     *
     * False for every boot that serves a request or inspects the app: a gate
     * decides what the app IS, and off means absent. True only for the boot that
     * compiles AGENTS.md ({@see \Lava\Core\Console\AppBoot::forMap()}), because
     * the map lists what the app DECLARES — a pack switched off in this
     * environment still declares its services, commands and routes, and a map
     * that dropped them read stale on every machine whose gate differed
     * (Lava Notes, R3-B11).
     */
    public bool $allPacksEnabled = false;

    /** @var list<\Lava\Core\Modules\ModuleRef> every entry of app/Modules.php */
    public array $moduleRefs = [];

    /** @var list<\Lava\Core\Modules\ModuleRef> modules whose feature resolved on */
    public array $enabledModules = [];

    /** @var list<\Lava\Core\Modules\ModuleRef> modules whose feature resolved off (loaded manifest-only) */
    public array $disabledModules = [];

    /** @var array<string, \Lava\Core\Modules\Module> wired module instances by class, in app/Modules.php order */
    public array $modules = [];

    /** @var array<string, \Lava\Core\Modules\PackInfo> manifests of disabled-but-installed packs, by class */
    public array $moduleManifests = [];

    /** @var array<string, string> valid KEY => VALUE pairs parsed from config/.env */
    public array $dotEnv = [];

    /**
     * @var array<string, string> the subset of dotEnv that boot actually
     *      promoted — i.e. names the real environment did NOT already define.
     *      A name in dotEnv but not here was overridden by the shell, which is
     *      exactly the distinction `lava env` has to report.
     */
    public array $envFromFile = [];

    /**
     * The 'set' section of config/features.php.
     *
     * `Flag`, not `mixed`: `CollectFlagDefinitions::absorbSet()` refuses any
     * entry that is not a `Flag` — with a problem naming the malformed entry —
     * before it stores one, so this holds only what passed that check. The
     * declaration was looser than the invariant and said the validation happened
     * later, in `BuildFeatures`, which is where the value is consumed rather than
     * checked.
     *
     * @var array<string, \Lava\Core\Features\Flag>
     */
    public array $rawFlagSet = [];

    /** @var list<string> global middleware class-strings from app/Middleware.php */
    public array $globalMiddleware = [];

    /**
     * The app's command set as RegisterCommands assembled it: core commands +
     * enabled modules' ({@see \Lava\Core\Modules\ProvidesCommands}) +
     * app/Commands.php. Null only when an upstream fatal stopped the chain
     * before that step ran.
     */
    public ?\Lava\Core\Console\CommandRegistry $commands = null;

    /**
     * Services a test substitutes, id => value, handed to the container when
     * RegisterCoreServices builds it. Empty for every real boot — see
     * {@see \Lava\Core\Testing\TestApp::boot()}.
     *
     * @var array<string, mixed>
     */
    public array $replacements = [];

    public function __construct(
        public readonly string $appDir,
        public readonly ProblemReport $problems,
    ) {
    }

    public function configPath(string $file): string
    {
        return $this->appDir . '/config/' . $file;
    }

    public function appPath(string $file): string
    {
        return $this->appDir . '/app/' . $file;
    }

    /** The value of a real environment variable (never the .env fallback). */
    public function realEnv(string $name): ?string
    {
        return \Lava\Core\Config\ProcessEnv::real($name);
    }

    /** Real env first, then the parsed config/.env values. */
    public function envValue(string $name): ?string
    {
        return $this->realEnv($name) ?? $this->dotEnv[$name] ?? null;
    }

    /**
     * Every pack manifest the app can see, by module class: enabled modules
     * contribute their live manifest, and disabled-but-installed ones the
     * manifest WireModules loaded. `lava about` and {@see RuntimeFacts} list
     * packs from this, with each gate's state read off moduleRefs.
     *
     * @return array<string, \Lava\Core\Modules\PackInfo>
     */
    public function packs(): array
    {
        $packs = $this->moduleManifests;
        foreach ($this->modules as $moduleClass => $module) {
            $packs[$moduleClass] = $module->pack();
        }

        return $packs;
    }
}