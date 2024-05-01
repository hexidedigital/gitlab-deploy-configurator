<?php

namespace App\Domains\DeployConfigurator\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

readonly class CiCdOptions implements Arrayable
{
    public const PrepareStage = 'prepare';
    public const BuildStage = 'build';
    public const DeployStage = 'deploy';

    public function __construct(
        public string $template_group,
        public ?string $template_key = null,
        public array $enabled_stages = [],
        public ?string $node_version = null,
        public ?string $build_folder = null,
        protected Collection $extra = new Collection(),
    ) {
    }

    public static function makeFromArray(array $array): CiCdOptions
    {
        return new self(
            template_group: data_get($array, 'template_group'),
            template_key: data_get($array, 'template_key'),
            enabled_stages: data_get($array, 'enabled_stages') ?: [],
            node_version: data_get($array, 'node_version'),
            build_folder: data_get($array, 'build_folder'),
            extra: new Collection(data_get($array, 'extra', [])),
        );
    }

    public static function getNodeVersions(): array
    {
        return [
            '22' => '22',
            '20' => '20',
            '18' => '18',
            '16' => '16',
            '14' => '14',
        ];
    }

    public function isStageDisabled(string $stageName): bool
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

    public function extra(string $key)
    {
        return data_get($this->extra, $key);
    }

    public function toArray(): array
    {
        return [
            'template_group' => $this->template_group,
            'template_key' => $this->template_key,
            'enabled_stages' => $this->enabled_stages,
            'node_version' => $this->node_version,
            'build_folder' => $this->build_folder,
            'extra' => $this->extra->toArray(),
        ];
    }
}
