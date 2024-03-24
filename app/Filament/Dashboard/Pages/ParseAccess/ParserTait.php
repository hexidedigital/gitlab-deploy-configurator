<?php

namespace App\Filament\Dashboard\Pages\ParseAccess;

use App\Parser\AccessParser;
use Gitlab;
use GrahamCampbell\GitLab\GitLabManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

trait ParserTait
{
    public array $projects = [];

    protected function parseAccessInput(?string $accessInput): AccessParser
    {
        $parser = new AccessParser();
        $parser->setAccessInput($accessInput);
        $parser->parseInputForAccessPayload();

        return $parser;
    }

    protected function loadProjects(): array
    {
        $gitLabManager = app(GitLabManager::class);

        $this->authenticateGitlabManager($gitLabManager, data_get($this, 'data.gitlab.project.token'), data_get($this, 'data.gitlab.project.domain'));

        return $this->fetchProjectFromGitlab($gitLabManager, [
            'order_by' => 'created_at',
        ]);
    }

    protected function authenticateGitlabManager(GitLabManager $gitLabManager, ?string $token, ?string $domain): void
    {
        $gitLabManager->setUrl($domain);
        $gitLabManager->authenticate($token, Gitlab\Client::AUTH_HTTP_TOKEN);
    }

    protected function fetchProjectFromGitlab(GitLabManager $gitLabManager, array $filters): array
    {
        $projects = $gitLabManager->projects()->all([
            'page' => 1,
            /*todo - temporary limit*/
//            'per_page' => 30,
            'per_page' => 5,
            'min_access_level' => 30, // developer
            ...$filters,
        ]);

        return (new Collection($projects))
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

    /*todo - find project in array and when missing - in api*/
    protected function findProject(string|int|null $id): ?array
    {
        return data_get($this->projects, $id);
    }

    // https://docs.gitlab.com/ee/api/access_requests.html#valid-access-levels
    protected function isValidAccessLevel($level): bool
    {
        return $level === 40 || $level === 50;
    }
}
