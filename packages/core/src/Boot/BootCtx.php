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

    /** @var array<string, mixed> raw 'set' section of config/features.php, validated by BuildFeatures */
    public array $rawFlagSet = [];

    /** @var list<string> global middleware class-strings from app/Middleware.php */
    public array $globalMiddleware = [];

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
}