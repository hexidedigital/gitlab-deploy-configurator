<?php

namespace App\Domains\DeployConfigurator\Data;

use Illuminate\Contracts\Support\Arrayable;

readonly class CodeInfoDetails implements Arrayable
{
    public function __construct(
        // general
        public ?string $repositoryTemplate = null,
        public ?string $frameworkVersion = null,
        public ?string $frontendBuilder = null,
        // backend details
        public bool $isLaravel = false,
        public ?string $adminPanel = null,
        // frontend details
        public bool $isNode = false,
    ) {
    }

    public static function makeFromArray(array $data): static
    {
        return new self(
            repositoryTemplate: data_get($data, 'repository_template'),
            frameworkVersion: data_get($data, 'framework_version'),
            frontendBuilder: data_get($data, 'frontend_builder'),
            isLaravel: data_get($data, 'is_laravel', false),
            adminPanel: data_get($data, 'admin_panel'),
            isNode: data_get($data, 'is_node', false),
        );
    }

    public function toArray(): array
    {
        return [
            'framework_version' => $this->frameworkVersion,
            'repository_template' => $this->repositoryTemplate,
            'frontend_builder' => $this->frontendBuilder,
            'is_laravel' => $this->isLaravel,
            'admin_panel' => $this->adminPanel,
            'is_node' => $this->isNode,
        ];
    }
}
