<?php

namespace App\Domains\DeployConfigurator\ContentGenerators;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\Data\CiCdOptions;
use App\Domains\DeployConfigurator\Data\TemplateInfo;

class GitlabCiCdYamlGenerator
{
    public function __construct(
        private readonly CiCdOptions $ciCdOptions,
    ) {
    }

    public function render(): string
    {
        $templateInfo = (new CiCdTemplateRepository())->getTemplateInfo($this->ciCdOptions->template_group, $this->ciCdOptions->template_key);

        return view('gitlab-templates.gitlab-ci-yml', [
            'templateInfo' => $templateInfo,
            'ciCdOptions' => $this->ciCdOptions,
            'variables' => $this->getVariables($templateInfo),
        ])->render();
    }

    protected function getVariables(?TemplateInfo $templateInfo): array
    {
        $variables = [];

        if ($this->ciCdOptions->isStageEnabled(CiCdOptions::BuildStage) || $templateInfo->group->isFrontend()) {
            $variables['NODE_VERSION'] = ['value' => $this->ciCdOptions->node_version];
        }

        if ($templateInfo->group->isFrontend()) {
            $variables['BUILD_FOLDER'] = ['value' => $this->ciCdOptions->build_folder];
        }

        if ($templateInfo->usesPM2()) {
            $variables['PROJECT_NAME'] = ['value' => $this->ciCdOptions->extra('pm2_name'), 'comment' => 'Name of the PM2 process'];
        }

        return $variables;
    }
}
