<?php

namespace App\Domains\DeployConfigurator\Data\Stage;

readonly class StageOptions
{
    public function __construct(
        public ?string $gitUrl,
        public ?string $baseDirPattern,
        public ?string $binComposer,
        public ?string $binPhp,
        public ?string $homeFolder,
        public SshOptions $ssh,
        public BashAliasesOptions $bashAliases,
    ) {
    }

    public static function makeFromArray(array $data): static
    {
        return new self(
            gitUrl: $data['git-url'] ?? $data['git_url'] ?? null,
            baseDirPattern: $data['base-dir-pattern'] ?? $data['base_dir_pattern'] ?? null,
            binComposer: $data['bin-composer'] ?? $data['bin_composer'] ?? null,
            binPhp: $data['bin-php'] ?? $data['bin_php'] ?? null,
            homeFolder: $data['home-folder'] ?? $data['home_folder'] ?? null,
            ssh: SshOptions::makeFromArray($data['ssh'] ?? []),
            bashAliases: BashAliasesOptions::makeFromArray($data['bash_aliases'] ?? []),
        );
    }
}
