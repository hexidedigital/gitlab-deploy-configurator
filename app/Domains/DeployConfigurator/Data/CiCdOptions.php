<?php

namespace App\Domains\DeployConfigurator\Data;

readonly class CiCdOptions
{
    public function __construct(
        public string $template_type,
        public string $template_version,
        public array $enabled_stages,
        public string $node_version,
    ) {
    }

    public static function makeFromArray(array $array): CiCdOptions
    {
        return new self(
            template_type: $array['template_type'],
            template_version: $array['template_version'],
            enabled_stages: $array['enabled_stages'],
            node_version: $array['node_version'],
        );
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
