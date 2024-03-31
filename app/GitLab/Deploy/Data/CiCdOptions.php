<?php

namespace App\GitLab\Deploy\Data;

readonly class CiCdOptions
{
    public function __construct(
        public string $template_version,
        public array $enabled_stages,
        public string $node_version,
    ) {
    }

    public function withDisableStages(): bool
    {
        return $this->template_version == '3.0';
    }

    public function isStagesDisabled(string $stageName): bool
    {
        return !$this->isStageEnabled($stageName);
    }

    public function isStageEnabled(string $stageName): bool
    {
        if (!isset($this->enabled_stages[$stageName])) {
            return true;
        }

        return $this->enabled_stages[$stageName];
    }
}
