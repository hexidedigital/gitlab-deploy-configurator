<?php

namespace App\Domains\DeployConfigurator;

use App\Domains\DeployConfigurator\Data\TemplateGroup;
use App\Domains\DeployConfigurator\Data\TemplateInfo;

class CiCdTemplateRepository
{
    public function latestForGroup(string $templateGroup): ?TemplateInfo
    {
        return collect($this->getTemplatesForGroup($templateGroup))
            ->filter(fn (TemplateInfo $template) => !$template->isDisabled)
            ->last();
    }

    /**
     * @param string|null $templateGroup
     * @return array<string, TemplateInfo>
     */
    public function getTemplatesForGroup(?string $templateGroup): array
    {
        return match ($templateGroup) {
            TemplateGroup::Backend => $this->backendTemplates($this->getTemplateGroups()[TemplateGroup::Backend]),
            TemplateGroup::Frontend => $this->frontendTemplates($this->getTemplateGroups()[TemplateGroup::Frontend]),
            default => [],
        };
    }

    /**
     * @return array{backend: TemplateGroup, frontend: TemplateGroup}
     */
    public function getTemplateGroups(): array
    {
        return [
            TemplateGroup::Backend => new TemplateGroup(
                key: TemplateGroup::Backend,
                name: 'Backend (Laravel)',
                icon: '🏴',
            ),
            TemplateGroup::Frontend => new TemplateGroup(
                key: TemplateGroup::Frontend,
                name: 'Frontend (Vue, React, Nuxt)',
                icon: '🦄',
            ),
        ];
    }

    public function getTemplateInfo(string $group, ?string $key): ?TemplateInfo
    {
        return collect($this->getTemplatesForGroup($group))->get($key);
    }

    /**
     * @return array<string, TemplateInfo>
     */
    protected function backendTemplates(TemplateGroup $group): array
    {
        return [
            'laravel_2_0' => new TemplateInfo(
                key: 'laravel_2_0',
                name: '2.0 - Webpack',
                templateName: 'laravel.2.0',
                group: $group,
                isDisabled: true,
            ),
            'laravel_2_1' => new TemplateInfo(
                key: 'laravel_2_1',
                name: '2.1 - Vite',
                templateName: 'laravel.2.1',
                group: $group,
                isDisabled: true,
            ),
            'laravel_2_2' => new TemplateInfo(
                key: 'laravel_2_2',
                name: '2.2 - Vite + Composer stage',
                templateName: 'laravel.2.2',
                group: $group,
                isDisabled: true,
            ),
            'laravel_3_0' => new TemplateInfo(
                key: 'laravel_3_0',
                name: '3.0 - Configurable',
                templateName: 'laravel.3.0',
                group: $group,
                isDisabled: false,
                allowToggleStages: true,
                canSelectNodeVersion: true,
            ),
        ];
    }

    /**
     * @return array<string, TemplateInfo>
     */
    protected function frontendTemplates(TemplateGroup $group): array
    {
        return [
            'react_3_0' => new TemplateInfo(
                key: 'react_3_0',
                name: 'React',
                templateName: 'react.3.0',
                group: $group,
                canSelectNodeVersion: true,
                hasBuildFolder: true,
                extra: [
                    'preferredBuildFolder' => 'build',
                    'preferredBuildType' => 'yarn',
                ],
            ),
            'vue_3_0' => new TemplateInfo(
                key: 'vue_3_0',
                name: 'Vue',
                templateName: 'vue.3.0',
                group: $group,
                canSelectNodeVersion: true,
                hasBuildFolder: true,
                extra: [
                    'preferredBuildFolder' => 'dist',
                    'preferredBuildType' => 'npm',
                ],
            ),
            'nuxt_3_0' => new TemplateInfo(
                key: 'nuxt_3_0',
                name: 'Nuxt',
                templateName: 'nuxt.3.0',
                group: $group,
                canSelectNodeVersion: true,
                hasBuildFolder: false,
                extra: [
                    'preferredBuildType' => 'npm',
                ],
            ),
        ];
    }
}
