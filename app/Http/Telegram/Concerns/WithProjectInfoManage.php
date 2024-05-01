<?php

namespace App\Http\Telegram\Concerns;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\Data\CodeInfoDetails;
use App\Domains\DeployConfigurator\Data\TemplateGroup;
use App\Domains\DeployConfigurator\DeployHelper;
use App\Domains\GitLab\Data\ProjectData;
use App\Domains\GitLab\ProjectValidator;
use App\Domains\GitLab\RepositoryParser;
use App\Exceptions\Telegram\Halt;
use Closure;
use DefStudio\Telegraph\Keyboard\Keyboard;
use Gitlab;
use Throwable;

trait WithProjectInfoManage
{
    protected RepositoryParser $repositoryParser;

    protected function resetProjectRelatedData(): void
    {
        //
    }

    protected function resolveProject(string|int|null $projectId): ?ProjectData
    {
        if (!$projectId) {
            return null;
        }

        try {
            return $this->gitLabService->throwOnGitlabException()->findProject($projectId);
        } catch (Gitlab\Exception\RuntimeException $exception) {
            $this->chat->message("❗ {$exception->getMessage()}")->send();
        } catch (Throwable $exception) {
            report($exception);

            $this->chat->message("❗ Unexpected error occurred! {$exception->getMessage()}")->send();
        }

        throw new Halt();
    }

    protected function validateProject(ProjectData $project): bool
    {
        $validator = $this->getProjectValidator($project)->validate();

        if (!$validator->failed()) {
            return true;
        }

        $this->handleValidationErrors($validator, $project);

        return false;
    }

    protected function handleValidationErrors(ProjectValidator $validator, ProjectData $project): void
    {
        if (!$validator->failed()) {
            $this->chatContext->pushToState([
                'rejected' => null,
            ]);

            return;
        }

        $messageBag = collect($validator->getMessages());

        foreach ($messageBag as $messages) {
            $this->reply($messages[0]);
        }

        if ($messageBag->has('access_to_settings')) {
            $this->chatContext->pushToState([
                'rejected' => ['message' => 'access_to_settings'],
            ]);

            if ($this->data->has('reload')) {
                $this->chat->message('You have no right access to settings for this project!')->send();

                return;
            }

            $this->chat->message('🔴 You have no right access to settings for this project!')->send();

            $this->chat
                ->markdown(sprintf("⚠ You have no right access to settings for this project! Your level for this project: *%s*", $project->level()->getLabel()))
                ->keyboard(Keyboard::make()->button('Reload')->action('selectProjectCallback')->param('id', $project->id)->param('reload', 1))
                ->send();

            return;
        }

        if ($messageBag->has('has_empty_repository')) {
            $this->chatContext->pushToState([
                'rejected' => ['message' => 'has_empty_repository'],
            ]);

            if ($this->data->has('reload')) {
                $this->chat->message('Repository is still empty. Please push the initial commit to the repository.')->send();

                return;
            }

            $this->chat->message('🔴 This project is empty! You must manually push the initial commit to the repository.')->send();

            $this->chat
                ->markdownV2('```bash' . PHP_EOL . DeployHelper::getScriptToCreateAndPushLaravelRepository($project) . PHP_EOL . '```')
                ->keyboard(Keyboard::make()->button('Reload')->action('selectProjectCallback')->param('id', $project->id)->param('reload', 1))
                ->send();

            return;
        }

        if ($messageBag->has('is_laravel_project')) {
            $this->chat->message('Sorry, but currently only Laravel projects are supported!')->send();

            return;
        }
    }

    protected function getProjectValidator(ProjectData $project): ProjectValidator
    {
        return ProjectValidator::makeForProject($project)
            ->bail()
            ->defaults()
            ->rule('is_laravel_project', function (ProjectData $projectData, Closure $fail) {
                $data = data_get($this->chatContext->context_data, 'projectInfo.codeInfo');
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

    protected function selectProject(string|int|null $projectId): ProjectData
    {
        $project = $this->resolveProject($projectId);
        if (is_null($project)) {
            $this->chat->message('Sorry, but I couldn\'t find the project you selected. Please try to select again.')->send();

            throw new Halt();
        }

        // fill basic project info
        $projectInfo = $this->getProjectInfo($project);
        $this->chatContext->pushToData([
            'projectInfo' => $projectInfo,
        ]);

        // save state about empty repository
        $this->chatContext->pushToState([
            'emptyRepo' => $project->hasEmptyRepository(),
        ]);

        $codeInfo = $this->parseProjectCode($project);

        $projectInfo['codeInfo'] = $codeInfo->toArray();

        $this->chatContext->pushToData([
            'projectInfo' => $projectInfo,
        ]);

        $validator = $this->getProjectValidator($project)
            ->bail(false)
            ->validate();

        $this->fillCiCdOptions($project, $codeInfo);

        $this->handleValidationErrors($validator, $project);

        if ($validator->failed()) {
            throw new Halt();
        }

        // delete message with project select keyboard
        $this->chat->deleteMessage($this->callbackQuery->message()->id())->send();

        return $project;
    }

    protected function getProjectInfo(ProjectData $project): array
    {
        return [
            'token' => $this->user->gitlab_token,
            'domain' => config('services.gitlab.url'),
            'selected_id' => $project->id,
            'project_id' => $project->id,
            'name' => $project->name,
            'web_url' => $project->web_url,
            'git_url' => $project->ssh_url_to_repo,
        ];
    }

    protected function parseProjectCode(ProjectData $project): CodeInfoDetails
    {
        $this->repositoryParser = new RepositoryParser($this->gitLabService);

        $codeInfo = collect()
            ->merge($this->parseTemplateForLaravel($project))
            ->merge($this->parseTemplateForLaravel($project))
            ->merge($this->determineProjectFrontendBuilder($project))
            ->all();

        return CodeInfoDetails::makeFromArray($codeInfo);
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
                $this->reply('Project does not have composer.json');
            } else {
                $this->reply('Failed to detect project template');
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
                $this->reply('Project does not have package.json');
            } else {
                $this->reply('Failed to detect project template');
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
                $this->reply('Project does not have package.json');
            } else {
                $this->reply('Failed to detect project frontend builder');
            }
        }

        return [];
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

        $this->chatContext->pushToData([
            'ci_cd_options' => array_merge($this->chatContext->context_data['ci_cd_options'], $newOptions),
        ]);
    }
}
