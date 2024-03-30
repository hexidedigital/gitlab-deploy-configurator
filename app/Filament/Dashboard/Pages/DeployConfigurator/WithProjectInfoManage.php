<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use App\GitLab\Data\ProjectData;
use Filament\Notifications\Notification;
use Gitlab;
use GuzzleHttp\Utils;

trait WithProjectInfoManage
{
    public function validateProjectData($projectId): bool
    {
        if (!$projectId) {
            return false;
        }

        $project = $this->findProject($projectId);

        $notification = Notification::make()->danger();

        if (is_null($project)) {
            return tap(false, fn () => $notification->title('Project not found!')->send());
        }

        if (!$project->level()->hasAccessToSettings()) {
            return tap(false, fn () => $notification->title('You have no access to settings for this project!')->send());
        }

        if (!$this->isLaravelRepository) {
            return tap(false, fn () => $notification->title('This is not a Laravel repository!')->send());
        }

        if ($project->hasEmptyRepository()) {
            return tap(false, fn () => $notification->title('This project is empty!')->send());
        }

        return true;
    }

    public function selectProject(string|int|null $projectID): void
    {
        $this->clearProjectInfo();

        $project = $this->findProject($projectID);

        if (is_null($project)) {
            return;
        }

        $this->fillProjectInfoForProject($project);

        if (!$project->level()->hasAccessToSettings()) {
            Notification::make()->title('You have no access to settings for this project!')->danger()->send();
        }

        $this->emptyRepo = $project->hasEmptyRepository();

        if ($project->hasEmptyRepository()) {
            Notification::make()->title('This project is empty!')->warning()->send();

            $this->fill([
                'data.init_repository' => $this->getScriptToCreateAndPushLaravelRepository($project),
            ]);
        }

        $this->determineProjectTemplate($project);
        $this->determineFrontendBuilderFromPackageJson($project);
    }

    protected function determineProjectTemplate(ProjectData $project): void
    {
        if ($project->hasEmptyRepository()) {
            $this->isLaravelRepository = true;

            $this->changeRepositoryTemplate('none (empty repo)');

            return;
        }

        $template = 'not resolved';

        // fetch project files

        try {
            $fileData = $this->getGitLabManager()->repositoryFiles()->getFile($project->id, 'composer.json', $project->default_branch);
            $composerJson = Utils::jsonDecode(base64_decode($fileData['content']), true);

            $laravelVersion = data_get($composerJson, 'require.laravel/framework');
            $usesYajra = data_get($composerJson, 'require.yajra/laravel-datatables-html');

            $this->fill([
                'data.projectInfo.laravel_version' => $laravelVersion,
            ]);

            $between = function ($v, $left, $right) {
                $v = str_replace('^', '', $v);

                return version_compare($v, $left, '>=')
                    && version_compare($v, $right, '<');
            };

            if ($usesYajra) {
                $template = $between($laravelVersion, 9, 10)
                    ? 'islm-template'
                    : 'old-template for laravel ' . $laravelVersion;
            } else {
                $template = $between($laravelVersion, 10, 11)
                    ? 'hd-based-template'
                    : 'laravel-11';
            }

            $this->isLaravelRepository = true;
        } catch (Gitlab\Exception\RuntimeException $e) {
            if ($e->getCode() == 404) {
                $this->isLaravelRepository = false;
                Notification::make()->title('Project does not have composer.json')->warning()->send();
            } else {
                Notification::make()->title('Failed to detect project template')->danger()->send();
            }
        }

        $this->changeRepositoryTemplate($template);
    }

    protected function determineFrontendBuilderFromPackageJson(ProjectData $project): void
    {
        if ($project->hasEmptyRepository()) {
            return;
        }

        try {
            $fileData = $this->getGitLabManager()->repositoryFiles()->getFile($project->id, 'package.json', $project->default_branch);
            $packageJson = Utils::jsonDecode(base64_decode($fileData['content']), true);

            // vite or webpack or not resolved
            $frontendBuilder = data_get($packageJson, 'devDependencies.vite')
                ? 'vite'
                : (data_get($packageJson, 'devDependencies.webpack') ? 'webpack' : null);

            $this->fill([
                'data.projectInfo.frontend_builder' => $frontendBuilder,
            ]);
        } catch (Gitlab\Exception\RuntimeException $e) {
            if ($e->getCode() === 404) {
                Notification::make()->title('Project does not have package.json')->warning()->send();
            } else {
                Notification::make()->title('Failed to detect project frontend builder')->danger()->send();
            }
        }
    }

    protected function clearProjectInfo(): void
    {
        $this->reset([
            'isLaravelRepository',
            'emptyRepo',
            'parsed',
            'data.init_repository',
            'data.projectInfo.laravel_version',
            'data.projectInfo.repository_template',
            'data.projectInfo.frontend_builder',
            'data.projectInfo.selected_id',
            'data.projectInfo.project_id',
            'data.projectInfo.name',
            'data.projectInfo.web_url',
            'data.projectInfo.git_url',
        ]);
    }

    protected function fillProjectInfoForProject(ProjectData $project): void
    {
        $this->fill([
            'data.projectInfo.selected_id' => $project->id,
            'data.projectInfo.project_id' => $project->id,
            'data.projectInfo.name' => $project->name,
            'data.projectInfo.web_url' => $project->web_url,
            'data.projectInfo.git_url' => $project->ssh_url_to_repo,
        ]);
    }

    protected function changeRepositoryTemplate(?string $template): void
    {
        $this->fill([
            'data.projectInfo.repository_template' => $template,
        ]);
    }

    protected function getRepositoryTemplates(): array
    {
        return [
            'laravel-11' => 'Laravel 11',
            'islm-template' => 'islm based template (laravel 9)',
            'hd-based-template' => 'HD-based v3 (laravel 8)',
        ];
    }

    private function getScriptToCreateAndPushLaravelRepository(ProjectData $project): ?string
    {
        return <<<BASH
laravel new --git --branch=develop --no-interaction {$project->name}
cd {$project->name}
git remote add origin {$project->getCloneUrl()}
git push --set-upstream origin develop
BASH;
    }
}
