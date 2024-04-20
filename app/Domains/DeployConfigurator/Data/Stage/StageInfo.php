<?php

namespace App\Domains\DeployConfigurator\Data\Stage;

use HexideDigital\GitlabDeploy\DeploymentOptions\Options\Database;
use HexideDigital\GitlabDeploy\DeploymentOptions\Options\Mail;
use HexideDigital\GitlabDeploy\DeploymentOptions\Options\Server;

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
            server: isset($data['server']) ? new Server($data['server']) : null,
            database: isset($data['database']) ? new Database($data['database']) : null,
            mail: isset($data['mail']) ? new Mail($data['mail']) : null,
        );
    }
}
