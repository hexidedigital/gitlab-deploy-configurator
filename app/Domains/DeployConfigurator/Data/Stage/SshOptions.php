<?php

namespace App\Domains\DeployConfigurator\Data\Stage;

readonly class SshOptions
{
    public function __construct(
        public bool $useCustomSshKey = false,
        public ?string $privateKey = null,
        public ?string $privateKeyPassword = null,
    ) {
    }

    public static function makeFromArray(array $data): static
    {
        return new self(
            useCustomSshKey: data_get($data, 'use_custom_ssh_key', false),
            privateKey: data_get($data, 'private_key'),
            privateKeyPassword: data_get($data, 'private_key_password'),
        );
    }
}
