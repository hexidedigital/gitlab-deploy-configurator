<?php

declare(strict_types=1);

namespace App\Domains\DeployConfigurator\DeploymentOptions;

use App\Domains\DeployConfigurator\DeploymentOptions\Options\Database;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Mail;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Options;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Server;

final class Stage
{
    public function __construct(
        public readonly string $name,
        public readonly Options $options,
        public readonly Server $server,
        public readonly ?Database $database = null,
        public readonly ?Mail $mail = null,
    ) {
    }

    public function hasMailOptions(): bool
    {
        return !is_null($this->mail);
    }
}
