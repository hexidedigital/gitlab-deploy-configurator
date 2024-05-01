<?php

declare(strict_types=1);

namespace App\Domains\DeployConfigurator\DeploymentOptions;

use HexideDigital\GitlabDeploy\Gitlab\GitlabProject;

final class Configurations
{
    public function __construct(
        public readonly float $version,
        public readonly GitlabProject $project,
        public readonly StageBag $stageBag,
    ) {
    }
}
