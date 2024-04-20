<?php

namespace App\Domains\DeployConfigurator;

use App\Domains\DeployConfigurator\Data\TemplateInfo;

class CiCdTemplateRepository
{
    /**
     * @param string|null $repositoryType
     * @return array<string, TemplateInfo>
     */
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

    public function getTemplateInfo(string $group, string $key): ?TemplateInfo
    {
        return collect($this->getTemplatesForType($group))->get($key);
    }

    /**
     * @return array<string, TemplateInfo>
     */
    protected function backendTemplates(): array
    {
        return [
            'laravel_2_0' => new TemplateInfo(
                key: 'laravel_2_0',
                name: '2.0 - Webpack',
                templateName: 'laravel.2.0',
                group: 'backend',
                isDisabled: true,
            ),
            'laravel_2_1' => new TemplateInfo(
                key: 'laravel_2_1',
                name: '2.1 - Vite',
                templateName: 'laravel.2.1',
                group: 'backend',
                isDisabled: true,
            ),
            'laravel_2_2' => new TemplateInfo(
                key: 'laravel_2_2',
                name: '2.2 - Vite + Composer stage',
                templateName: 'laravel.2.2',
                group: 'backend',
                isDisabled: true,
            ),
            'laravel_3_0' => new TemplateInfo(
                key: 'laravel_3_0',
                name: '3.0 - Configurable',
                templateName: 'laravel.3.0',
                group: 'backend',
                isDisabled: false,
                allowToggleStages: true,
                canSelectNodeVersion: true,
            ),
        ];
    }

    /**
     * @return array<string, TemplateInfo>
     */
    protected function frontendTemplates(): array
    {
        return [
            'react_latest' => new TemplateInfo(
                key: 'react_latest',
                name: 'react (latest)',
                templateName: 'react.latest',
                group: 'frontend',
                isDisabled: false,
                canSelectNodeVersion: false,
            ),
            'vue_2_0' => new TemplateInfo(
                key: 'vue_2_0',
                name: 'vue (2.0)',
                templateName: 'vue.2.0',
                group: 'frontend',
                isDisabled: false,
                canSelectNodeVersion: false,
            ),
            'vue_latest' => new TemplateInfo(
                key: 'vue_latest',
                name: 'vue (latest)',
                templateName: 'vue.latest',
                group: 'frontend',
                isDisabled: false,
                canSelectNodeVersion: true,
            ),
        ];
    }
}
