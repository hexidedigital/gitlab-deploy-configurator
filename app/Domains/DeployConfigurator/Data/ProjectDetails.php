<?php

namespace App\Domains\DeployConfigurator\Data;

readonly class ProjectDetails
{
    public function __construct(
        public string $token,
        public string $domain,
        public int $project_id,
        public string $name,
        public string $web_url,
        public string $git_url,
        public array $codeInfo,
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
            codeInfo: [
                'laravel_version' => data_get($projectInfo, 'codeInfo.laravel_version'),
                'repository_template' => data_get($projectInfo, 'codeInfo.repository_template'),
                'frontend_builder' => data_get($projectInfo, 'codeInfo.frontend_builder'),
                'is_laravel' => data_get($projectInfo, 'codeInfo.is_laravel'),
                'admin_panel' => data_get($projectInfo, 'codeInfo.admin_panel'),
            ],
        );
    }
}
