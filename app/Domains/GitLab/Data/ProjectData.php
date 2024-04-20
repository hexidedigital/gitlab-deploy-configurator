<?php

namespace App\Domains\GitLab\Data;

use App\Domains\GitLab\Enums\AccessLevel;
use Illuminate\Support\Arr;

final readonly class ProjectData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $name_with_namespace,
        public string $path,
        public string $path_with_namespace,
        public string $ssh_url_to_repo,
        public string $default_branch,
        public string $web_url,
        public string|null $avatar_url,
        protected bool $empty_repo,
        protected array $permissions,
        protected array $namespace,
    ) {
    }

    public static function makeFrom(array $attributes): ProjectData
    {
        return new ProjectData(...Arr::only($attributes, [
            'id',
            'name',
            'name_with_namespace',
            'path',
            'path_with_namespace',
            'ssh_url_to_repo',
            'default_branch',
            'web_url',
            'avatar_url',
            'empty_repo',
            'permissions',
            'namespace',
        ]));
    }

    public function getNameForSelect(): string
    {
        return "{$this->name} ({$this->namespace['full_path']})";
    }

    public function hasEmptyRepository(): bool
    {
        return $this->empty_repo;
    }

    public function getCloneUrl(): string
    {
        return $this->ssh_url_to_repo;
    }

    public function level(): AccessLevel
    {
        return $this->determineProjectAccessLevel();
    }

    private function determineProjectAccessLevel(): AccessLevel
    {
        return AccessLevel::tryFrom(
            data_get(
                $this->permissions,
                'group_access.access_level',
                data_get($this->permissions, 'project_access.access_level')
            )
        ) ?: AccessLevel::Undefined;
    }
}
