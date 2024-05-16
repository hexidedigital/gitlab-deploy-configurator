<?php

namespace App\Console\Commands;

use App\Domains\DeployConfigurator\Jobs\CleanUpRepositoryForTestingAction;
use App\Domains\DeployConfigurator\LogWriter;
use App\Domains\GitLab\GitLabService;
use Illuminate\Console\Command;

class CleanUpRepositoryCommand extends Command
{
    protected $signature = 'gitlab:clean-up-repository {id}';

    protected $description = 'Clean up repository by id';

    public function handle(): void
    {
        $token = config('services.gitlab.token');
        if (!$token) {
            $this->error('GitLab token is not set');

            return;
        }

        $id = $this->argument('id');

        $this->info("Cleaning up repository '{$id}'");

        $gitLabService = resolve(GitLabService::class)->authenticateUsing($token);

        $project = $gitLabService->findProject($id);
        if (!$project) {
            $this->error("Project with id '{$id}' not found");

            return;
        }

        if (!$this->confirm("Are you sure you want to clean up repository '{$project->name}'")) {
            return;
        }

        (new CleanUpRepositoryForTestingAction(
            $gitLabService,
            new LogWriter(),
            $project,
        ))->isTestingProject(true)->execute();

        $this->info("Repository '{$project->name}' cleaned up");
    }
}
