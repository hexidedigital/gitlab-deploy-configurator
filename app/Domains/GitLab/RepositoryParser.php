<?php

namespace App\Domains\GitLab;

use App\Domains\GitLab\Data\ProjectData;
use GuzzleHttp\Utils;

class RepositoryParser
{
    public function __construct(
        protected GitLabService $gitLabService,
    ) {
    }

    public function parseTemplateForLaravel(ProjectData $project): array
    {
        $composerJson = Utils::jsonDecode($this->gitLabService->throwOnGitlabException()->getFileContent($project, 'composer.json'), true);

        $laravelVersion = data_get($composerJson, 'require.laravel/framework');

        $adminPanel = $this->getResult([
            'adminlte' => fn () => data_get($composerJson, 'require.jeroennoten/laravel-adminlte'),
            'filament' => fn () => data_get($composerJson, 'require.filament/filament'),
            'voyager' => fn () => data_get($composerJson, 'require.tcg/voyager'),
        ], 'no admin panel');

        $template = $this->chooseTemplateForLaravelVersion($composerJson, $adminPanel);

        return [
            'repository_template' => $template,
            'framework_version' => $laravelVersion,
            'is_laravel' => true,
            'admin_panel' => $adminPanel,
        ];
    }

    public function parseTemplateForFrontend(ProjectData $project): array
    {
        $packageJson = Utils::jsonDecode($this->gitLabService->throwOnGitlabException()->getFileContent($project, 'package.json'), true);

        $getVersion = fn (string $package) => data_get($packageJson, "devDependencies.{$package}", data_get($packageJson, "dependencies.{$package}"));

        $framework = $this->getResult([
            'nuxt' => fn () => !is_null($getVersion('nuxt')),
            'react' => fn () => !is_null($getVersion('react')),
            'vue' => fn () => !is_null($getVersion('vue')),
        ], 'no frontend framework');

        $frameworkVersion = $getVersion($framework);

        return [
            'repository_template' => $framework,
            'framework_version' => $frameworkVersion,
            'is_node' => true,
        ];
    }

    public function determineFrontendBuilderFromPackageJson(ProjectData $project): array
    {
        $packageJson = Utils::jsonDecode($this->gitLabService->throwOnGitlabException()->getFileContent($project, 'package.json'), true);

        $getVersion = fn (string $package) => data_get($packageJson, "devDependencies.{$package}", data_get($packageJson, "dependencies.{$package}"));

        $builder = $this->getResult([
            'vite' => fn () => !is_null($getVersion('vite')),
            'webpack' => fn () => !is_null($getVersion('webpack')),
            'laravel-mix' => fn () => !is_null($getVersion('laravel-mix')),
        ], 'no frontend builder or not resolved');

        return [
            'frontend_builder' => $builder,
        ];
    }

    public static function getRepositoryTemplateName(?string $name): ?string
    {
        return match ($name) {
            'laravel-11' => 'Laravel 11',
            'islm-based-template' => 'islm based template (Laravel ~9)',
            'hd-based-template-8' => 'HD-based v3 (Laravel ~8)',
            'old-hd-base-template' => 'Old HD-based template',
            null, '' => null,
            default => 'not resolved',
        };
    }

    protected function chooseTemplateForLaravelVersion(array $composerJson, string $adminPanel): string
    {
        $laravelVersion = data_get($composerJson, 'require.laravel/framework');
        $usesYajra = data_get($composerJson, 'require.yajra/laravel-datatables-html');

        $between = function ($left, $right) use ($laravelVersion) {
            $v = str_replace('^', '', $laravelVersion);

            return version_compare($v, $left, '>=')
                && version_compare($v, $right, '<');
        };

        $isAdminlte = $adminPanel === 'adminlte';

        $templates = [
            // without yajra
            'laravel-11' => fn () => !$usesYajra && $between(11, 12),
            'hd-based-template-8' => fn () => !$usesYajra && $isAdminlte && $between(8, 10),
            // with yajra
            'islm-based-template' => fn () => $usesYajra && $isAdminlte && $between(9, 11),
            'old-hd-base-template' => fn () => $usesYajra && $isAdminlte && $between(5, 9),
        ];

        return $this->getResult($templates, '');
    }

    protected function getResult(array $conditions, $default = null): ?string
    {
        foreach ($conditions as $name => $condition) {
            if ($condition()) {
                return $name;
            }
        }

        return value($default);
    }
}
