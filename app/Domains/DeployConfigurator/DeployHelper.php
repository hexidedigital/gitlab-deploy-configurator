<?php

namespace App\Domains\DeployConfigurator;

use App\Domains\GitLab\Data\ProjectData;

final class DeployHelper
{
    public static function getScriptToCreateAndPushLaravelRepository(ProjectData $project): ?string
    {
        return /* @lang Shell Script */ <<<BASH
            laravel new --git --branch=develop --no-interaction {$project->name}
            cd {$project->name}
            git remote add origin {$project->getCloneUrl()}
            git push --set-upstream origin develop
            BASH;
    }
}
