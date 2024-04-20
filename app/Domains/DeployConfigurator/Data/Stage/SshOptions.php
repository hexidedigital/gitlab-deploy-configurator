<?php

namespace App\Domains\DeployConfigurator\Data\Stage;

class SshOptions
{
    public function __construct(
        public bool $useCustomSshKey,
        public ?string $privateKey = null,
        public ?string $privateKeyPassword = null,
    ) {
    }

    public static function makeFromArray(array $data): static
    {
        return new self(
            useCustomSshKey: $data['use_custom_ssh_key'],
            privateKey: $data['private_key'],
            privateKeyPassword: $data['private_key_password'],
        );
    }
}
