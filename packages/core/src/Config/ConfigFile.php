<?php

declare(strict_types=1);

namespace Lava\Core\Config;

use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\ProblemReport;

/**
 * Loading one config file into a {@see Config}, with its provenance.
 *
 * Core config files and pack config files go through the same code, so a
 * value from config/database.php is read, validated, and reported exactly the
 * way one from config/app.php is. Two loaders would drift: the second one
 * would be the one that forgot to record provenance, and `lava config` would
 * then be unable to say where a pack's value came from.
 *
 * Keys become `"<name>.<key>"` — the file's bare name, so `config/database.php`
 * yields `database.dsn`. That is what makes a pack's config indistinguishable
 * from core's to every reader downstream.
 */
final class ConfigFile
{
    /**
     * Reads a config file and folds it into the Config.
     *
     * A missing file is not a problem: config files are optional, and a pack
     * that declares one nobody created simply contributes no keys — its
     * defaults, or its env vars, are then what the app runs on.
     *
     * @param string $path the path to read
     * @param string $name the bare config name, e.g. "database"
     * @param string $label how the file is named in reports, e.g. "config/database.php"
     */
    public static function load(Config $config, string $path, string $name, string $label, ProblemReport $problems): Config
    {
        if (!is_file($path)) {
            return $config;
        }

        try {
            $loaded = require $path;
        } catch (\Throwable $previous) {
            $problems->add(InvalidConfig::threw($path, $name, $previous));
            return $config;
        }

        if (!is_array($loaded)) {
            $problems->add(InvalidConfig::notAnArray($path, $name, get_debug_type($loaded)));
            return $config;
        }

        return self::absorb($config, $name, $loaded, $label, $problems);
    }

    /**
     * Folds an already-loaded array into the Config.
     *
     * A non-string key is reported and skipped rather than coerced: PHP would
     * happily turn it into a string, and `[0 => 'x']` becoming `database.0`
     * is a config key nobody wrote and nobody can find.
     *
     * @param array<mixed> $loaded
     */
    public static function absorb(
        Config $config,
        string $name,
        array $loaded,
        string $label,
        ProblemReport $problems,
    ): Config {
        foreach ($loaded as $key => $value) {
            if (!is_string($key)) {
                $problems->add(InvalidConfig::notAStringKey($label, $name, $key));
                continue;
            }
            $config = $config->with("{$name}.{$key}", $value, $label);
        }

        return $config;
    }
}
