<?php

namespace App\Parser;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class AccessParser
{
    const YML_CONFIG_VERSION = 1.4;
    private string $input = '';
    private array $accessInfo = [];
    private array $configurations = [];
    private array $notResolved = [];

    public function __construct()
    {
        // ...
    }

    public function setAccessInput(string $input): void
    {
        $this->input = $input;
    }

    public function setConfigurations(array $configurations): void
    {
        $this->configurations = $configurations;
    }

    public function getAccessInfo(): array
    {
        return $this->accessInfo;
    }

    public function getNotResolved(): array
    {
        return $this->notResolved;
    }

    public function buildDeployPrepareConfig(): array
    {
        $stageConfig = [
            'name' => data_get($this->configurations, 'stage.name'),
            'options' => [
                'git-url' => data_get($this->configurations, 'stage.options.git_url'),
                'base-dir-pattern' => data_get($this->configurations, 'stage.options.base_dir_pattern'),
                'bin-composer' => data_get($this->configurations, 'stage.options.bin_composer'),
                'bin-php' => data_get($this->configurations, 'stage.options.bin_php'),
            ],
            ...collect($this->accessInfo)
                ->only(['database', 'mail', 'server'])
                ->all(),
        ];

        return [
            'version' => self::YML_CONFIG_VERSION,
            'git-lab' => [
                'project' => [
                    'token' => data_get($this->configurations, 'gitlab.project.token'),
                    'project-id' => data_get($this->configurations, 'gitlab.project.project_id'),
                    'domain' => data_get($this->configurations, 'gitlab.project.domain'),
                ],
            ],
            'stages' => [
                $stageConfig,
            ],
        ];
    }

    public function parseInputForAccessPayload(): void
    {
        $this->notResolved = [];

        $detected = [
            'database' => false,
            'mail' => false,
            'server' => false,
        ];

        $this->accessInfo = collect(explode("\n", $this->input))
            ->chunkWhile(fn ($line) => !empty(trim($line)))
            ->mapWithKeys(function (Collection $lines, int $chunk) use (&$detected) {
                $lines = $lines->filter(fn ($line) => str($line)->squish()->isNotEmpty())->values();

                $type = str($lines->first())->lower();

                if ($type->startsWith('mysql') && !$detected['database']) {
                    $detected['database'] = true;

                    return [
                        'database' => $this->parseDatabaseLines($lines),
                    ];
                }
                if ($type->startsWith(['mail', 'smtp']) && !$detected['mail']) {
                    $detected['mail'] = true;

                    return [
                        'mail' => $this->parseMailLines($lines),
                    ];
                }
                if ($type->isMatch('/\w+.\w+/') && !$detected['server']) {
                    $detected['server'] = true;

                    return [
                        'server' => $this->parseServerLines($lines),
                    ];
                }

                $this->notResolved[] = [
                    'chunk' => $chunk,
                    'lines' => $lines,
                ];

                return [
                    'skip' => null,
                ];
            })
            ->filter()
            ->toArray();
    }

    public function makeDeployPrepareYmlFile(): string
    {
        $contents = $this->contentForDeployPrepareConfig();

        $file = 'deploy-prepare.yml';

        $filesystem = Storage::disk('local');

        $filesystem->put($file, $contents);

        return $filesystem->path($file);
    }

    public function makeDeployerPhpFile(): string
    {
        $contents = $this->contentForDeployerScript();

        $file = 'deploy.php';

        $filesystem = Storage::disk('local');

        $filesystem->put($file, $contents);

        return $filesystem->path($file);
    }

    public function contentForDeployPrepareConfig(): string
    {
        return Yaml::dump($this->buildDeployPrepareConfig(), 4, 2);
    }

    public function contentForDeployerScript(): string
    {
        return view('deployer', [
            'applicationName' => data_get($this->configurations, 'gitlab.project.name'),
            'githubOathToken' => data_get($this->configurations, 'xxxxgithubOathTokenxxxxx'),
            'CI_REPOSITORY_URL' => data_get($this->configurations, 'stage.options.git_url'),
            'CI_COMMIT_REF_NAME' => data_get($this->configurations, 'stage.name'),
            'BIN_PHP' => data_get($this->configurations, 'stage.options.bin_php'),
            'BIN_COMPOSER' => data_get($this->configurations, 'stage.options.bin_composer'),
            'DEPLOY_SERVER' => $server = data_get($this->configurations, 'stage.server.host'),
            'DEPLOY_USER' => $user = data_get($this->configurations, 'stage.server.user'),
            'DEPLOY_BASE_DIR' => Str::replace(
                ['{{HOST}}', '{{USER}}'],
                [$server, $user],
                data_get($this->configurations, 'stage.options.base_dir_pattern')
            ),
            'SSH_PORT' => data_get($this->configurations, 'stage.server.port', 22),
        ])->render();
    }

    private function parseServerLines(Collection $lines): array
    {
        return $lines->filter()->skip(1)
            ->values()
            ->mapWithKeys(function ($line) {
                preg_match("/(?<key>\\w+):\\s*(?<value>.*)/", $line, $match);

                $key = str($match['key'])->trim()->lower()->value();

                return [
                    $key => trim($match['value']),
                ];
            })
            ->toArray();
    }

    private function parseDatabaseLines(Collection $lines): array
    {
        $lines = $lines
            ->skip(1)
            ->values();

        return [
            'database' => $lines[0] ?? null,
            'username' => $lines[1] ?? null,
            'password' => $lines[2] ?? null,
        ];
    }

    private function parseMailLines(Collection $lines): array
    {
        return $lines->filter()->skip(1)
            ->values()
            ->mapWithKeys(function ($line) {
                preg_match("/(?<key>\\w+):\\s*(?<value>.*)/", $line, $match);

                $key = str($match['key'])->trim()->lower()->value();

                return [
                    $key => trim($match['value']),
                ];
            })
            ->toArray();
    }
}
