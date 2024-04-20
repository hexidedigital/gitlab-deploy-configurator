<?php

namespace App\Domains\DeployConfigurator\Jobs;

use App\Domains\DeployConfigurator\LogWriter;
use App\Domains\GitLab\Data\ProjectData;
use App\Domains\GitLab\GitLabService;

class CleanUpRepositoryForTestingAction
{
    private bool $isTestingProject = false;

    public function __construct(
        private readonly GitLabService $gitLabService,
        private readonly LogWriter $logWriter,
        private readonly ProjectData $projectData,
    ) {
    }

    public function isTestingProject(bool $isTestingProject): static
    {
        $this->isTestingProject = $isTestingProject;

        return $this;
    }

    public function execute(): void
    {
        $this->logWriter->debug("Cleaning up repository '{$this->projectData->name}'");

        if (!$this->isTestingProject) {
            $this->logWriter->debug("Repository '{$this->projectData->name}' is not in testing projects");

            return;
        }

        $this->deleteVariables();

        $this->deleteTestBranches();

        $this->deleteDeployKeys();

        $this->logWriter->debug("Repository '{$this->projectData->name}' cleaned up");
    }

    public function deleteVariables(): void
    {
        $this->logWriter->debug("Deleting variables for project '{$this->projectData->name}'");

        collect($this->gitLabService->gitLabManager()->projects()->variables($this->projectData->id))
            // remove all variables except the ones with environment_scope = '*'
            ->reject(fn (array $variable) => str($variable['environment_scope']) == '*')
            ->each(fn (array $variable) => $this->gitLabService->gitLabManager()->projects()->removeVariable($this->projectData->id, $variable['key'], [
                'filter' => ['environment_scope' => $variable['environment_scope']],
            ]));
    }

    public function deleteTestBranches(): void
    {
        $this->logWriter->debug("Deleting test branches for project '{$this->projectData->name}'");

        collect($this->gitLabService->gitLabManager()->repositories()->branches($this->projectData->id))
            ->reject(fn (array $branch) => str($branch['name'])->startsWith(['develop']))
            ->filter(fn (array $branch) => str($branch['name'])->startsWith(['test', 'dev']))
            ->each(fn (array $branch) => $this->gitLabService->gitLabManager()->repositories()->deleteBranch($this->projectData->id, $branch['name']));
    }

    public function deleteDeployKeys(): void
    {
        $this->logWriter->debug("Deleting deploy keys for project '{$this->projectData->name}'");

        collect($this->gitLabService->gitLabManager()->projects()->deployKeys($this->projectData->id))
            ->filter(fn (array $key) => str($key['title'])->startsWith(['web-templatelte']))
            ->each(fn (array $key) => $this->gitLabService->gitLabManager()->projects()->deleteDeployKey($this->projectData->id, $key['id']));
    }
}
