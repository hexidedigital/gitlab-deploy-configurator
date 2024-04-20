<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use App\Domains\GitLab\GitLabService;
use GrahamCampbell\GitLab\GitLabManager;

trait WithGitlabService
{
    protected GitLabService $gitlabService;

    public function gitlabService(): GitLabService
    {
        if (isset($this->gitlabService)) {
            return $this->gitlabService;
        }

        return tap($this->gitlabService = app(GitLabService::class), function (GitLabService $service) {
            $service->authenticateUsing(
                token: data_get($this, 'data.projectInfo.token'),
                domain: data_get($this, 'data.projectInfo.domain')
            );
        });
    }

    public function getGitLabManager(): GitLabManager
    {
        return $this->gitlabService()->gitLabManager();
    }
}
