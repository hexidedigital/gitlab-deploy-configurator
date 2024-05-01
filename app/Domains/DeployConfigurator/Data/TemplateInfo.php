<?php

namespace App\Domains\DeployConfigurator\Data;

readonly class TemplateInfo
{
    public function __construct(
        public string $key,
        public string $name,
        public string $templateName,
        public TemplateGroup $group,
        public bool $isDisabled = false,
        public bool $allowToggleStages = false,
        public bool $canSelectNodeVersion = false,
        public bool $hasBuildFolder = false,
        protected array $extra = [],
    ) {
    }

    public function usesPM2(): bool
    {
        return str($this->key)->contains(['nuxt']);
    }

    public function preferredBuildFolder(): ?string
    {
        return data_get($this->extra, 'preferredBuildFolder');
    }

    public function preferredBuildType(): ?string
    {
        return data_get($this->extra, 'preferredBuildType');
    }
}
