<?php

namespace App\Domains\DeployConfigurator;

use App\Domains\DeployConfigurator\Data\TemplateInfo;

class CiCdTemplateRepository
{
    /**
     * @param string|null $templateGroup
     * @return array<string, TemplateInfo>
     */
    public function getTemplatesForGroup(?string $templateGroup): array
    {
        return match ($templateGroup) {
            'backend' => $this->backendTemplates(),
            'frontend' => $this->frontendTemplates(),
            default => [],
        };
    }

    public function templateGroups(): array
    {
        return [
            'backend' => [
                'key' => 'backend',
                'name' => 'Backend (Laravel)',
                'icon' => '🏴',
            ],
            'frontend' => [
                'key' => 'frontend',
                'name' => 'Frontend (Vue, React)',
                'icon' => '🦄',
            ],
        ];
    }

    public function getTemplateInfo(string $group, string $key): ?TemplateInfo
    {
        return collect($this->getTemplatesForGroup($group))->get($key);
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
                isDisabled: true,
                canSelectNodeVersion: false,
                hasBuildFolder: false,
            ),
            'vue_2_0' => new TemplateInfo(
                key: 'vue_2_0',
                name: 'vue (2.0)',
                templateName: 'vue.2.0',
                group: 'frontend',
                isDisabled: true,
                canSelectNodeVersion: false,
                hasBuildFolder: false,
            ),
            'vue_latest' => new TemplateInfo(
                key: 'vue_latest',
                name: 'vue (latest)',
                templateName: 'vue.latest',
                group: 'frontend',
                isDisabled: false,
                canSelectNodeVersion: true,
                hasBuildFolder: true,
            ),
        ];
    }
}
