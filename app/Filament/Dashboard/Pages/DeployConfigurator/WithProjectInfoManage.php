<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\Data\CodeInfoDetails;
use App\Domains\DeployConfigurator\Data\TemplateGroup;
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
                $data = data_get($this, 'data.projectInfo.codeInfo');
                $codeInfo = CodeInfoDetails::makeFromArray($data ?: []);
                if (empty($data) || $codeInfo->isLaravel) {
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
        return true;
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

        $codeInfo = $this->parseProjectInfo($project);

        $this->fill([
            'data.projectInfo.codeInfo' => $codeInfo->toArray(),
        ]);

        $validator = $this->getProjectValidator($project)
            ->bail(false)
            ->validate();

        $this->fillCiCdOptions($project, $codeInfo);

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
        if ($project->hasEmptyRepository() || str($project->name)->contains('Front')) {
            return [
                'is_laravel' => false,
            ];
        }

        try {
            return $this->repositoryParser->parseTemplateForLaravel($project);
        } catch (Gitlab\Exception\RuntimeException $e) {
            if ($e->getCode() == 404) {
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

    protected function parseTemplateForFrontend(ProjectData $project): array
    {
        if ($project->hasEmptyRepository()) {
            return [
                'is_node' => false,
            ];
        }

        try {
            return $this->repositoryParser->parseTemplateForFrontend($project);
        } catch (Gitlab\Exception\RuntimeException $e) {
            if ($e->getCode() == 404) {
                Notification::make()->title('Project does not have package.json')->warning()->send();
            } else {
                Notification::make()->title('Failed to detect project template')->danger()->send();
            }

            return [
                'repository_template' => 'not resolved',
                'is_node' => false,
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

    protected function parseProjectInfo(ProjectData $project): CodeInfoDetails
    {
        $this->repositoryParser = new RepositoryParser($this->gitlabService());

        $codeInfo = collect()
            ->merge($this->parseTemplateForLaravel($project))
            ->merge($this->parseTemplateForFrontend($project))
            ->merge($this->determineProjectFrontendBuilder($project))
            ->all();

        return CodeInfoDetails::makeFromArray($codeInfo);
    }

    protected function fillCiCdOptions(ProjectData $project, CodeInfoDetails $codeInfo): void
    {
        if ($project->hasEmptyRepository()) {
            return;
        }

        if ($codeInfo->isLaravel) {
            $newOptions = [
                'template_group' => TemplateGroup::Backend,
                'template_key' => (new CiCdTemplateRepository())->latestForGroup(TemplateGroup::Backend)->key,
            ];
        } else {
            $newOptions = [
                'template_group' => TemplateGroup::Frontend,
                'template_key' => null,
                'extra' => [
                    'pm2_name' => str($project->name)->after('.')->beforeLast('.')->lower()->snake()->value(),
                ],
            ];
        }

        $this->fill(Arr::dot($newOptions, 'data.ci_cd_options.'));
    }
}
