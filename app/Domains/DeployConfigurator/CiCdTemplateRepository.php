<?php

namespace App\Domains\DeployConfigurator;

class CiCdTemplateRepository
{
    public function getTemplatesForType(?string $repositoryType): array
    {
        return match ($repositoryType) {
            'backend' => $this->backendTemplates(),
            'frontend' => $this->frontendTemplates(),
            default => [],
        };
    }

    public function templateTypes(): array
    {
        return [
            'backend' => 'Backend (Laravel)',
            'frontend' => 'Frontend (Vue, React)',
        ];
    }

    public function getTemplateInfo(string $repositoryType, string $template): ?array
    {
        return collect($this->getTemplatesForType($repositoryType))
            ->get($template);
    }

    protected function backendTemplates(): array
    {
        return [
            'laravel_2_0' => [
                'name' => '2.0 - Webpack',
                'disabled' => true,
                'templateName' => 'laravel.2.0',
            ],
            'laravel_2_1' => [
                'name' => '2.1 - Vite',
                'disabled' => true,
                'templateName' => 'laravel.2.1',
            ],
            'laravel_2_2' => [
                'name' => '2.2 - Vite + Composer stage',
                'disabled' => true,
                'templateName' => 'laravel.2.2',
            ],
            'laravel_3_0' => [
                'name' => '3.0 - Configurable',
                'disabled' => false,
                'templateName' => 'laravel.3.0',
                'configure_stages' => true,
                'change_node_version' => true,
            ],
        ];
    }

    protected function frontendTemplates(): array
    {
        return [
            'react_latest' => [
                'name' => 'react (latest)',
                'disabled' => false,
                'templateName' => 'react.latest',
                'change_node_version' => false,
            ],
            'vue_2_0' => [
                'name' => 'vue (2.0)',
                'disabled' => false,
                'templateName' => 'vue.2.0',
                'change_node_version' => false,
            ],
            'vue_latest' => [
                'name' => 'vue (latest)',
                'disabled' => false,
                'templateName' => 'vue.latest',
                'change_node_version' => true,
            ],
        ];
    }
}
