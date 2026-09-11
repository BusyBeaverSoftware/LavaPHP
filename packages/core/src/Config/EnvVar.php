<?php

declare(strict_types=1);

namespace Lava\Core\Config;

/**
 * A declared environment variable: what it is for, whether it is required,
 * and whether its value is a secret (redacted by `lava env` unless --reveal).
 * Packs declare theirs via PackInfo; apps declare theirs in app/Services.php
 * by registering a list of EnvVar values under the id 'app.env_vars'.
 */
final readonly class EnvVar
{
    /**
     * The container id an app registers its declared EnvVars under. Boot
     * validates the shape once (see WireAppServices) so `lava env` reads a
     * list of EnvVar and never has to defend against arbitrary values.
     */
    public const CONTAINER_ID = 'app.env_vars';

    public function __construct(
        public string $name,
        public bool $required,
        public string $description = '',
        public bool $secret = false,
    ) {
    }

    public static function required(string $name, string $description = '', bool $secret = false): self
    {
        return new self($name, true, $description, $secret);
    }

    public static function optional(string $name, string $description = '', bool $secret = false): self
    {
        return new self($name, false, $description, $secret);
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'name' => $this->name,
            'required' => $this->required,
            'description' => $this->description,
            'secret' => $this->secret,
        ];
    }
}