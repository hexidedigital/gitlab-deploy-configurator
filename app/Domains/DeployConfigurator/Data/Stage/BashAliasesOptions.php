<?php

namespace App\Domains\DeployConfigurator\Data\Stage;

readonly class BashAliasesOptions
{
    public function __construct(
        public bool $insert = false,
        public bool $artisanCompletion = false,
        public bool $artisanAliases = false,
        public bool $composerAlias = false,
        public bool $folderAliases = false,
    ) {
    }

    public static function makeFromArray(array $data): static
    {
        return new self(
            insert: data_get($data, 'insert', false),
            artisanCompletion: data_get($data, 'artisanCompletion', false),
            artisanAliases: data_get($data, 'artisanAliases', false),
            composerAlias: data_get($data, 'composerAlias', false),
            folderAliases: data_get($data, 'folderAliases', false),
        );
    }
}
