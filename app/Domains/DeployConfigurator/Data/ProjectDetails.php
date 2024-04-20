<?php

namespace App\Domains\DeployConfigurator\Data;

use Illuminate\Contracts\Support\Arrayable;

readonly class ProjectDetails implements Arrayable
{
    public function __construct(
        public string $token,
        public string $domain,
        public int $project_id,
        public string $name,
        public string $web_url,
        public string $git_url,
        public CodeInfoDetails $codeInfo,
    ) {
    }

    public static function makeFromArray(array $projectInfo): self
    {
        return new ProjectDetails(
            token: data_get($projectInfo, 'token'),
            domain: data_get($projectInfo, 'domain'),
            project_id: data_get($projectInfo, 'project_id'),
            name: data_get($projectInfo, 'name'),
            web_url: data_get($projectInfo, 'web_url'),
            git_url: data_get($projectInfo, 'git_url'),
            codeInfo: CodeInfoDetails::makeFromArray(data_get($projectInfo, 'codeInfo') ?: []),
        );
    }

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'domain' => $this->domain,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'web_url' => $this->web_url,
            'git_url' => $this->git_url,
            'codeInfo' => $this->codeInfo->toArray(),
        ];
    }
}
