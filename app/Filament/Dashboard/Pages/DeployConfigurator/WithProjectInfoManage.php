<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use App\Domains\DeployConfigurator\DeployHelper;
use App\Domains\GitLab\Data\ProjectData;
use App\Domains\GitLab\ProjectValidator;
use App\Domains\GitLab\RepositoryParser;
use Closure;
use Filament\Notifications\Notification;
use Gitlab;
use Illuminate\Support\Arr;
use Throwable;

trait WithProjectInfoManage
{
    protected RepositoryParser $repositoryParser;

    public function resolveProject(string|int|null $projectId): ?ProjectData
    {
        if (!$projectId) {
            return null;
        }

        try {
            return $this->gitlabService()->throwOnGitlabException()->findProject($projectId);
        } catch (Gitlab\Exception\RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->danger()->title('Unexpected error occurred!')->body($exception->getMessage())->send();
        }

        return null;
    }

    public function validateProject(ProjectData $project): bool
    {
        $validator = $this->getProjectValidator($project)->validate();

        if (!$validator->failed()) {
            return true;
        }

        $this->handleValidationErrors($validator);

        return false;
    }

    protected function handleValidationErrors(ProjectValidator $validator): void
    {
        if (!$validator->failed()) {
            return;
        }

        foreach ($validator->getMessages() as $name => $messages) {
            $notification = Notification::make()->title($messages[0])->danger();

            if ('is_laravel_project' === $name) {
                $notification->body('Currently only Laravel projects are supported!');
            } elseif ('has_empty_repository' === $name) {
                $notification->warning();
            }

            $notification->send();
        }
    }

    protected function getProjectValidator(ProjectData $project): ProjectValidator
    {
        return ProjectValidator::makeForProject($project)
            ->bail()
            ->defaults()
            ->rule('is_laravel_project', function (ProjectData $projectData, Closure $fail) {
                $codeInfo = data_get($this, 'data.projectInfo.codeInfo');
                if (empty($codeInfo) || data_get($codeInfo, 'is_laravel')) {
                    return;
                }

                if ($this->isFrontendProjectsAllowed()) {
                    return;
                }

                $fail('This is not a Laravel repository!');
            });
    }

    protected function isFrontendProjectsAllowed(): bool
    {
        return false;
    }

    public function selectProject(string|int|null $projectId): void
    {
        $project = $this->resolveProject($projectId);
        if (is_null($project)) {
            return;
        }

        $projectInfo = $this->getProjectInfo($project);

        $this->fill(Arr::dot($projectInfo, 'data.projectInfo.'));

        $this->emptyRepo = $project->hasEmptyRepository();
        $this->repositoryParser = new RepositoryParser($this->gitlabService());

        $codeInfo = collect()
            ->merge($this->parseTemplateForLaravel($project))
            ->merge($this->determineProjectFrontendBuilder($project));

        $this->fill([
            'data.projectInfo.codeInfo' => $codeInfo->all(),
        ]);

        $validator = $this->getProjectValidator($project)
            ->bail(false)
            ->validate();

        if (data_get($this, 'data.projectInfo.codeInfo.is_laravel')) {
            $this->fill(Arr::dot([
                'template_type' => 'backend',
                'template_version' => 'laravel_3_0',
            ], 'data.ci_cd_options.'));
        } else {
            $this->fill(Arr::dot([
                'template_type' => 'frontend',
                'template_version' => null,
            ], 'data.ci_cd_options.'));
        }

        $this->handleValidationErrors($validator);

        if (collect($validator->getMessages())->has('has_empty_repository')) {
            $this->fill([
                'data.init_repository_script' => DeployHelper::getScriptToCreateAndPushLaravelRepository($project),
            ]);
        }
    }

    protected function getProjectInfo(ProjectData $project): array
    {
        return [
            'selected_id' => $project->id,
            'project_id' => $project->id,
            'name' => $project->name,
            'web_url' => $project->web_url,
            'git_url' => $project->ssh_url_to_repo,
        ];
    }

    protected function parseTemplateForLaravel(ProjectData $project): array
    {
        if ($project->hasEmptyRepository()) {
            return [
                'is_laravel' => false,
            ];
        }

        try {
            return $this->repositoryParser->parseTemplateForLaravel($project);
        } catch (Gitlab\Exception\RuntimeException $e) {
            if ($e->getCode() == 404 && !str($project->name)->contains('Front')) {
                Notification::make()->title('Project does not have composer.json')->warning()->send();
            } else {
                Notification::make()->title('Failed to detect project template')->danger()->send();
            }

            return [
                'repository_template' => 'not resolved',
                'is_laravel' => false,
            ];
        }
    }

    protected function determineProjectFrontendBuilder(ProjectData $project): array
    {
        if ($project->hasEmptyRepository()) {
            return [];
        }

        try {
            return $this->repositoryParser->determineFrontendBuilderFromPackageJson($project);
        } catch (Gitlab\Exception\RuntimeException $e) {
            if ($e->getCode() === 404) {
                Notification::make()->title('Project does not have package.json')->warning()->send();
            } else {
                Notification::make()->title('Failed to detect project frontend builder')->danger()->send();
            }
        }

        return [];
    }
}
