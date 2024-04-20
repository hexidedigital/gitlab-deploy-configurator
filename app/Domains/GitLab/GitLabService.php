<?php

namespace App\Domains\GitLab;

use App\Domains\GitLab\Data\ProjectData;
use App\Domains\GitLab\Enums\AccessLevel;
use Closure;
use Gitlab;
use GrahamCampbell\GitLab\GitLabManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Tappable;

class GitLabService
{
    use Tappable;

    protected GitLabManager $gitLabManager;

    protected Closure $exceptionHandler;
    protected Closure $authenticator;
    protected ?string $token = null;
    protected ?string $domain = null;

    public function __construct()
    {
        $this->ignoreOnGitlabException();
    }

    public function throwOnGitlabException(): static
    {
        return $this->tap(fn () => $this->exceptionHandler = function (self $service, Gitlab\Exception\RuntimeException $exception) {
            throw $exception;
        });
    }

    public function ignoreOnGitlabException(): static
    {
        return $this->tap(fn () => $this->exceptionHandler = function (self $service, Gitlab\Exception\RuntimeException $exception) {
            // do nothing
        });
    }

    public function gitLabManager(): GitLabManager
    {
        if (isset($this->gitLabManager)) {
            return $this->gitLabManager;
        }

        return tap($this->gitLabManager = app(GitLabManager::class), function (GitLabManager $manager) {
            $this->authenticate($manager, $this->token, $this->domain);
        });
    }

    public function authenticateUsing(string $token, ?string $domain = null): static
    {
        return $this->tap(function () use ($token, $domain) {
            $this->token = $token;
            $this->domain = $domain ?: config('services.gitlab.url');
        });
    }

    public function authenticate(GitLabManager $manager, ?string $token, ?string $domain): void
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
        try {
            $projects = $this->gitLabManager()->projects()->all([
                'page' => 1,
                'per_page' => 15,
                'min_access_level' => AccessLevel::Developer->value,
                'order_by' => 'created_at',
                ...$filters,
            ]);

            return (new Collection($projects))
                ->keyBy('id')
                ->mapWithKeys(fn (array $project) => [
                    $project['id'] => ProjectData::makeFrom($project),
                ]);
        } catch (Gitlab\Exception\RuntimeException $exception) {
            ($this->exceptionHandler)($this, $exception);
        }

        return new Collection();
    }

    public function findProject(string|int|null $projectId): ?ProjectData
    {
        return $this->executeWithExceptionHandler(function () use ($projectId) {
            if (empty($projectId)) {
                return null;
            }

            $projectData = $this->gitLabManager()->projects()->show($projectId);

            return ProjectData::makeFrom($projectData);
        });
    }

    public function hasFile(ProjectData $projectData, string $path): bool
    {
        return !is_null($this->getFileContent($projectData, $path));
    }

    public function getFileContent(ProjectData $projectData, string $path): ?string
    {
        return $this->executeWithExceptionHandler(function () use ($path, $projectData) {
            $fileData = $this->gitLabManager()->repositoryFiles()->getFile($projectData->id, $path, $projectData->default_branch);

            return base64_decode($fileData['content']) ?: null;
        });
    }

    protected function executeWithExceptionHandler(Closure $callback, ?Closure $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (Gitlab\Exception\RuntimeException $exception) {
            ($this->exceptionHandler)($this, $exception);
        }

        return $fallback ? $fallback() : null;
    }
}
