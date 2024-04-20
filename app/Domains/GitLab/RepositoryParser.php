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

        $adminPanel = $this->checkForAdminPanel($composerJson);

        $template = $this->chooseTemplateForLaravelVersion($composerJson, $adminPanel);

        return [
            'repository_template' => $template,
            'laravel_version' => $laravelVersion,
            'is_laravel' => true,
            'admin_panel' => $adminPanel,
        ];
    }

    public function determineFrontendBuilderFromPackageJson(ProjectData $project): array
    {
        $packageJson = Utils::jsonDecode($this->gitLabService->throwOnGitlabException()->getFileContent($project, 'package.json'), true);

        $isInDependencies = fn (string $package) => data_get($packageJson, "devDependencies.{$package}", data_get($packageJson, "dependencies.{$package}"));

        $builders = [
            'vite',
            'webpack',
            'laravel-mix',
        ];

        $frontendBuilder = '-';
        foreach ($builders as $builder) {
            if ($isInDependencies($builder)) {
                $frontendBuilder = $builder;

                break;
            }
        }

        return [
            'frontend_builder' => $frontendBuilder,
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
            'laravel-11' => fn ($v) => !$usesYajra && $between(11, 12),
            'hd-based-template-8' => fn ($v) => !$usesYajra && $isAdminlte && $between(8, 10),
            // with yajra
            'islm-based-template' => fn ($v) => $usesYajra && $isAdminlte && $between(9, 11),
            'old-hd-base-template' => fn ($v) => $usesYajra && $isAdminlte && $between(5, 9),

            '' => fn ($v) => true,
        ];

        foreach ($templates as $template => $condition) {
            if ($condition($laravelVersion)) {
                break;
            }
        }

        return $template;
    }

    protected function checkForAdminPanel(array $composerJson): string
    {
        $adminPanels = [
            'adminlte' => fn () => data_get($composerJson, 'require.jeroennoten/laravel-adminlte'),
            'filament' => fn () => data_get($composerJson, 'require.filament/filament'),
            'voyager' => fn () => data_get($composerJson, 'require.tcg/voyager'),
            'no admin panel' => fn () => true,
        ];

        foreach ($adminPanels as $adminPanel => $condition) {
            if ($condition()) {
                break;
            }
        }

        return $adminPanel;
    }
}
