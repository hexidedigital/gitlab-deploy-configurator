<?php

namespace App\Domains\DeployConfigurator\Data\Stage;

readonly class BashAliasesOptions
{
    public function __construct(
        public bool $insert,
        public bool $artisanCompletion,
        public bool $artisanAliases,
        public bool $composerAlias,
        public bool $folderAliases,
    ) {
    }

    public static function makeFromArray(array $data): static
    {
        return new self(
            insert: $data['insert'],
            artisanCompletion: $data['artisanCompletion'],
            artisanAliases: $data['artisanAliases'],
            composerAlias: $data['composerAlias'],
            folderAliases: $data['folderAliases'],
        );
    }
}
