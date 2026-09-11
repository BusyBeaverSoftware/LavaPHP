<?php

declare(strict_types=1);

namespace Lava\Core\Modules;

use Lava\Core\Problem\InvalidConfig;

/**
 * A pack's manifest: identity plus the config files and env vars it reads.
 * The identity pair (package, feature) deliberately duplicates what the
 * app's ModuleRef says — boot cross-checks the two sources and a mismatch
 * is fatal, because a silent disagreement would make every flag report
 * ambiguous about which source to trust.
 */
final readonly class PackInfo
{
    /**
     * @param list<string> $configFiles config file names, without the config/ prefix
     * @param list<string> $envVars environment variable names the pack reads
     */
    private function __construct(
        public string $package,
        public string $feature,
        public array $configFiles,
        public array $envVars,
    ) {
    }

    /**
     * @param array<mixed> $configFiles
     * @param array<mixed> $envVars
     */
    public static function of(string $package, string $feature, array $configFiles = [], array $envVars = []): self
    {
        $problems = [];
        if (preg_match('/^lava\/[a-z0-9-]+$/', $package) !== 1) {
            $problems[] = "package '{$package}' should look like lava/<pack-name>";
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/', $feature) !== 1) {
            $problems[] = "feature '{$feature}' should be snake_case";
        }
        // Params are array<mixed> because this factory's job is to validate
        // hand-written pack manifests; each surviving name is collected into a
        // typed list, so the constructor only ever sees list<string>.
        $vars = [];
        foreach ($envVars as $name) {
            if (!is_string($name) || $name === '') {
                $problems[] = 'env var names must be non-empty strings';
                continue;
            }
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
                $problems[] = "env var name '{$name}' should be UPPER_SNAKE";
            }
            $vars[] = $name;
        }
        $configs = [];
        foreach ($configFiles as $name) {
            if (!is_string($name) || $name === '') {
                $problems[] = 'config file names must be non-empty strings';
                continue;
            }
            $configs[] = $name;
        }
        if ($problems !== []) {
            throw new InvalidConfig(
                'Invalid PackInfo: ' . implode('; ', array_unique($problems)) . '.',
                "Build it as PackInfo::of('lava/<pack>', '<snake_case>', configFiles: […], envVars: […]).",
                ['package' => $package, 'feature' => $feature],
            );
        }
        return new self($package, $feature, $configs, $vars);
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'package' => $this->package,
            'feature' => $this->feature,
            'config_files' => $this->configFiles,
            'env_vars' => $this->envVars,
        ];
    }
}