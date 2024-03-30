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

    protected function getGitLabManager(): GitLabManager
    {
        return tap($this->gitLabManager ??= app(GitLabManager::class), function (GitLabManager $manager) {
            $this->authenticateGitlabManager(
                $manager,
                data_get($this, 'data.projectInfo.token'),
                data_get($this, 'data.projectInfo.domain')
            );
        });
    }

    protected function authenticateGitlabManager(GitLabManager $manager, ?string $token, ?string $domain): void
    {
        $manager->setUrl($domain);
        $manager->authenticate($token, Gitlab\Client::AUTH_HTTP_TOKEN);
    }

    /**
     * @return Collection<int, ProjectData>
     */
    protected function loadProjects(): Collection
    {
        return $this->fetchProjectFromGitLab();
    }

    /**
     * @param array<string, int|string> $filters
     * @return Collection<int, ProjectData>
     */
    protected function fetchProjectFromGitLab(array $filters = []): Collection
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

    protected function findProject(string|int|null $projectId): ?ProjectData
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

    /**
     * todo WIP
     */
    protected function createCommitWithConfigFiles(): void
    {
        $project_id = 689;
        $stageName = 'test/dev/' . now()->format('His');

        $project = $this->findProject($project_id);

        $branches = collect($this->getGitLabManager()->repositories()->branches($project_id))
            ->keyBy('name');

        if (empty($branches)) {
            return;
        }

        return;

        // todo
        $branches
            ->filter(fn (array $branch) => str($branch)->startsWith('test'))
            ->each(function (array $branch) use ($project_id) {
                $this->getGitLabManager()->repositories()->deleteBranch($project_id, $branch['name']);
            });

        $defaultBranch = $project['default_branch'];
        if (!$branches->has($stageName)) {
//            $newBranch = $this->getGitLabManager()->repositories()->createBranch($project_id, $stageName, $defaultBranch);
        }

        // https://docs.gitlab.com/ee/api/commits.html#create-a-commit-with-multiple-files-and-actions
        $this->getGitLabManager()->repositories()->createCommit($project_id, [
            "branch" => $stageName,
            "start_branch" => $defaultBranch,
            "commit_message" => "Configure deployment " . now()->format('H:i:s'),
            "author_name" => "DeployHelper",
            "author_email" => "deploy-helper@hexide-digital.com",
            "actions" => [
                [
                    "action" => "create",
                    "file_path" => ".deploy/config-encoded.yml",
                    "content" => base64_encode("test payload in base64"),
                    "encoding" => "base64",
                ],
                [
                    "action" => "create",
                    "file_path" => ".deploy/config-raw.yml",
                    "content" => "test payload in raw",
                ],
            ],
        ]);
    }
}
