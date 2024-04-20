<?php

namespace App\Domains\DeployConfigurator\Data;

use Illuminate\Contracts\Support\Arrayable;

readonly class CodeInfoDetails implements Arrayable
{
    public function __construct(
        public ?string $laravelVersion = null,
        public ?string $repositoryTemplate = null,
        public ?string $frontendBuilder = null,
        public bool $isLaravel = false,
        public ?string $adminPanel = null,
    ) {
    }

    public static function makeFromArray(array $data): static
    {
        return new self(
            laravelVersion: data_get($data, 'laravel_version'),
            repositoryTemplate: data_get($data, 'repository_template'),
            frontendBuilder: data_get($data, 'frontend_builder'),
            isLaravel: data_get($data, 'is_laravel', false),
            adminPanel: data_get($data, 'admin_panel'),
        );
    }

    public function toArray(): array
    {
        return [
            'laravel_version' => $this->laravelVersion,
            'repository_template' => $this->repositoryTemplate,
            'frontend_builder' => $this->frontendBuilder,
            'is_laravel' => $this->isLaravel,
            'admin_panel' => $this->adminPanel,
        ];
    }
}
