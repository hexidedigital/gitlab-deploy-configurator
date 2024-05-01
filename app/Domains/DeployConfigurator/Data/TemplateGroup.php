<?php

namespace App\Domains\DeployConfigurator\Data;

readonly class TemplateGroup
{
    public const Backend = 'backend';
    public const Frontend = 'frontend';

    public function __construct(
        public string $key,
        public string $name,
        public string $icon,
    ) {
    }

    public function nameAndIcon(): string
    {
        return "{$this->name} {$this->icon}";
    }

    public function isFrontend(): bool
    {
        return $this->key === self::Frontend;
    }

    public function isBackend(): bool
    {
        return $this->key === self::Backend;
    }
}
