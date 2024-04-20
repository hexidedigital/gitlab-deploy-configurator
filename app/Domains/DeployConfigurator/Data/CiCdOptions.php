<?php

namespace App\Domains\DeployConfigurator\Data;

use Illuminate\Contracts\Support\Arrayable;

readonly class CiCdOptions implements Arrayable
{
    public function __construct(
        public string $template_group,
        public string $template_key,
        public array $enabled_stages,
        public string $node_version,
    ) {
    }

    public static function makeFromArray(array $array): CiCdOptions
    {
        return new self(
            template_group: $array['template_group'],
            template_key: $array['template_key'],
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

    public function toArray(): array
    {
        return [
            'template_group' => $this->template_group,
            'template_key' => $this->template_key,
            'enabled_stages' => $this->enabled_stages,
            'node_version' => $this->node_version,
        ];
    }
}
