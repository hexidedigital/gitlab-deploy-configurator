<?php

namespace App\Domains\DeployConfigurator\ContentGenerators;

use App\Domains\DeployConfigurator\Data\ProjectDetails;
use App\Domains\DeployConfigurator\Data\Stage\StageInfo;
use Illuminate\Support\Str;

class DeployerPhpGenerator
{
    public function __construct(
        private readonly ProjectDetails $projectDetails,
    ) {
    }

    public function render(?StageInfo $stageConfig = null, bool $generateWithVariables = false): string
    {
        $stageName = $stageConfig?->name;

        $renderVariables = !is_null($stageName) && !is_null($stageConfig) && $generateWithVariables;

        return view('deployer', [
            'applicationName' => $this->projectDetails->name,
            'githubOathToken' => config('services.gitlab.deploy_token'),
            'renderVariables' => $renderVariables,
            ...($stageConfig && $renderVariables ? [
                'CI_REPOSITORY_URL' => $this->projectDetails->git_url,
                'CI_COMMIT_REF_NAME' => $stageName,
                'BIN_PHP' => $stageConfig->options->binPhp,
                'BIN_COMPOSER' => $stageConfig->options->binComposer,
                'DEPLOY_SERVER' => $server = $stageConfig->server->host,
                'DEPLOY_USER' => $user = $stageConfig->server->login,
                'DEPLOY_BASE_DIR' => Str::replace(
                    ['{{HOST}}', '{{USER}}'],
                    [$server, $user],
                    $stageConfig->options->baseDirPattern,
                ),
                'SSH_PORT' => $stageConfig->server->sshPort,
            ] : []),
        ])->render();
    }
}
