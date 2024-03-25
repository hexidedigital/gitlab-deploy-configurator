<?php

namespace App\Filament\Dashboard\Pages\ParseAccess;

use Gitlab;
use GrahamCampbell\GitLab\GitLabManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

trait WithGitlab
{
    protected GitLabManager $gitLabManager;

    public array $projects = [];

    protected function makeGitlabManager(): void
    {
        $this->gitLabManager ??= app(GitLabManager::class);

        $this->authenticateGitlabManager(data_get($this, 'data.projectInfo.token'), data_get($this, 'data.projectInfo.domain'));
    }

    protected function loadProjects(): array
    {
        $this->makeGitlabManager();

        return $this->fetchProjectFromGitlab([
            'order_by' => 'created_at',
        ]);
    }

    protected function authenticateGitlabManager(?string $token, ?string $domain): void
    {
        $this->gitLabManager->setUrl($domain);
        $this->gitLabManager->authenticate($token, Gitlab\Client::AUTH_HTTP_TOKEN);
    }

    protected function fetchProjectFromGitlab(array $filters): array
    {
        $projects = $this->gitLabManager->projects()->all([
            'page' => 1,
            /* todo - temporary limit */
            'per_page' => 30,
//            'per_page' => 5,
            'min_access_level' => 30, // developer
            ...$filters,
        ]);

        return (new Collection($projects))
            ->keyBy('id')
            ->mapWithKeys(fn (array $project) => [
                $project['id'] => Arr::only($project, [
                    'id',
                    'name',
                    'name_with_namespace',
                    'ssh_url_to_repo',
                    'default_branch',
                    'web_url',
                    'avatar_url',
                    'empty_repo',
                    'permissions',
                ]),
            ])
            ->toArray();
    }

    /* todo - find project in array and when missing - in api */
    protected function findProject(string|int|null $id): ?array
    {
        return data_get($this->projects, $id);
    }

    // https://docs.gitlab.com/ee/api/access_requests.html#valid-access-levels
    protected function isValidAccessLevel($level): bool
    {
        return in_array(intval($level), [40, 50]);
    }

    protected function determineAccessLevelLabel($level): string
    {
        return match (intval($level)) {
            0 => 'No access ' . $level,
            5 => 'Minimal access',
            10 => 'Guest',
            20 => 'Reporter',
            30 => 'Developer',
            40 => 'Maintainer',
            50 => 'Owner',
            default => '(not-detected) . ' . $level,
        };
    }

    protected function createCommitWithConfigFiles(): void
    {
        $this->makeGitlabManager();

        $project_id = 689;
        $stageName = 'test/dev/' . now()->format('His');

        $project = $this->findProject($project_id);

        $branches = collect($this->gitLabManager->repositories()->branches($project_id))
            ->keyBy('name');

        if (empty($branches)) {
            return;
        }
        return;

        // todo
        $branches
            ->filter(fn (array $branch) => str($branch)->startsWith('test'))
            ->each(function (array $branch) use ($project_id) {
                $this->gitLabManager->repositories()->deleteBranch($project_id, $branch['name']);
            });

        $defaultBranch = $project['default_branch'];
        if (!$branches->has($stageName)) {
//            $newBranch = $this->gitLabManager->repositories()->createBranch($project_id, $stageName, $defaultBranch);
        }

        // https://docs.gitlab.com/ee/api/commits.html#create-a-commit-with-multiple-files-and-actions
        $this->gitLabManager->repositories()->createCommit($project_id, [
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
