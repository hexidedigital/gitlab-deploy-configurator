<?php

namespace App\Domains\DeployConfigurator\Data;

class ParsedServerInfo
{
    public function __construct(
        public string|null $homeFolderPath = null,
        public array $phpInfo = [],
        public string|null $phpOutput = null,
        public string|null $phpVersion = null,
        public string|null $composerOutput = null,
        public string|null $composerVersion = null,
        public array $paths = [],
    ) {
    }
}
