<?php

namespace App\Domains\DeployConfigurator\Data;

readonly class TemplateInfo
{
    public function __construct(
        public string $key,
        public string $name,
        public string $templateName,
        public string $group,
        public bool $isDisabled = false,
        public bool $allowToggleStages = false,
        public bool $canSelectNodeVersion = false,
    ) {
    }
}
