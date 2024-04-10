<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

class SampleFormData
{
    public function getSampleInput(): string
    {
        return <<<'DOC'
            web.example.nwdev.net
            Domain: https://web.example.nwdev.net
            Host: web.example.nwdev.net
            Login: web-example-dev
            Password: XxXxXxXXXXX

            MySQL:
            web-example-dev_db
            web-example-dev_db
            XXxxxxxXXXXXXxx

            SMTP:
            Hostname: devs.hexide-digital.com
            Username: example@nwdev.net
            Password: XxXxXxXXXXX
            DOC;
    }

    public function getDefaultStageOptions(): array
    {
        return [
            'base_dir_pattern' => '/home/{{USER}}/web/{{HOST}}/public_html',
            'home_folder' => '/home/{{USER}}',
            'bin_composer' => '/usr/bin/php8.2 /usr/bin/composer',
            'bin_php' => '/usr/bin/php8.2',
        ];
    }

    public function getProjectInfoData(?string $gitLabToken = null): array
    {
        return [
            'token' => $gitLabToken ?: config('services.gitlab.token'),
            'domain' => config('services.gitlab.url'),

            'selected_id' => null,
            'name' => null,
            'project_id' => null,
            'git_url' => null,
        ];
    }

    public function getCiCdOptions(): array
    {
        return [
            'template_version' => '3.0',
            'node_version' => '20',
            'enabled_stages' => [
                'prepare' => true,
                'build' => true,
                'deploy' => true,
            ],
        ];
    }

    public function getSampleStages(bool $includeStage = false): array
    {
        $sampleInput = $this->getSampleInput();
        $defaultStageOptions = $this->getDefaultStageOptions();

        return array_filter([
            [
                'name' => 'dev',
                'access_input' => $sampleInput,
                'options' => [
                    ...$defaultStageOptions,
                ],
            ],
            ($includeStage ? [
                'name' => 'stage',
                'access_input' => str($sampleInput)->replace([
                    'nwdev.net',
                    'dev',
                ], [
                    'hdit.info',
                    'stage',
                ])->toString(),
                'options' => [
                    ...$defaultStageOptions,
                ],
            ] : null),
        ]);
    }
}
