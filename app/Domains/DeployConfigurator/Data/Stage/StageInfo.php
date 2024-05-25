<?php

namespace App\Domains\DeployConfigurator\Data\Stage;

use App\Domains\DeployConfigurator\DeploymentOptions\Options\Database;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Mail;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Server;

readonly class StageInfo
{
    public function __construct(
        public string $name,
        public StageOptions $options,
        public Server $server,
        public ?Database $database = null,
        public ?Mail $mail = null,
    ) {
    }

    public static function makeFromArray(array $data): static
    {
        return new self(
            name: $data['name'],
            options: StageOptions::makeFromArray($data['options']),
            server: isset($data['server']) ? new Server($data['server']) : new Server([]),
            database: isset($data['database']) ? new Database($data['database']) : null,
            mail: isset($data['mail']) ? new Mail($data['mail']) : null,
        );
    }
}
