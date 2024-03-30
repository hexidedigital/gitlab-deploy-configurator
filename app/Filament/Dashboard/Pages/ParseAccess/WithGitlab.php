<?php

namespace App\Filament\Dashboard\Pages\ParseAccess;

use App\GitLab\Data\ProjectData;
use App\GitLab\Enums\AccessLevel;
use Gitlab;
use GrahamCampbell\GitLab\GitLabManager;
use Illuminate\Support\Collection;

trait WithGitlab
{
    protected GitLabManager $gitLabManager;

    public function getGitLabManager(): GitLabManager
    {
        return tap($this->gitLabManager ??= app(GitLabManager::class), function (GitLabManager $manager) {
            $this->authenticateGitlabManager(
                $manager,
                data_get($this, 'data.projectInfo.token'),
                data_get($this, 'data.projectInfo.domain')
            );
        });
    }

    public function authenticateGitlabManager(GitLabManager $manager, ?string $token, ?string $domain): void
    {
        $manager->setUrl($domain);
        $manager->authenticate($token, Gitlab\Client::AUTH_HTTP_TOKEN);
    }

    /**
     * @param array<string, int|string> $filters
     * @return Collection<int, ProjectData>
     */
    public function fetchProjectFromGitLab(array $filters = []): Collection
    {
        $projects = $this->getGitLabManager()->projects()->all([
            'page' => 1,
            'per_page' => 20,
            'min_access_level' => AccessLevel::Developer->value,
            'order_by' => 'created_at',
            ...$filters,
        ]);

        return (new Collection($projects))
            ->keyBy('id')
            ->mapWithKeys(fn (array $project) => [
                $project['id'] => ProjectData::makeFrom($project),
            ]);
    }

    public function findProject(string|int|null $projectId): ?ProjectData
    {
        try {
            if (empty($projectId)) {
                return null;
            }

            $projectData = $this->getGitLabManager()->projects()->show($projectId);

            return ProjectData::makeFrom($projectData);
        } catch (Gitlab\Exception\RuntimeException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }
}
