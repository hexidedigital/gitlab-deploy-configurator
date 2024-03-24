<?php

namespace App\Parser;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class AccessParser
{
    private const YML_CONFIG_VERSION = 1.4;

    private array $accessInfo = [];
    private array $configurations = [];
    private array $notResolved = [];

    public function __construct()
    {
        // ...
    }

    public function setConfigurations(array $configurations): void
    {
        $this->configurations = $configurations;
    }

    public function getAccessInfo(?string $stageName = null): ?array
    {
        return $stageName
            ? data_get($this->accessInfo, $stageName)
            : $this->accessInfo;
    }

    public function getNotResolved(?string $stageName = null): array
    {
        return $stageName
            ? data_get($this->notResolved, $stageName, [])
            : $this->notResolved;
    }

    public function buildDeployPrepareConfig(?string $stageName = null): array
    {
        $stages = collect(data_get($this->configurations, 'stages'))
            ->filter(function (array $stageConfig) use ($stageName) {
                return ($stageConfig['can_be_parsed'] ?? false)
                    || $stageName === $stageConfig['name'];
            })
            ->map(function (array $stageConfig) {
                $stageName = data_get($stageConfig, 'name');
                $this->parseInputForAccessPayload($stageName, data_get($stageConfig, 'access_input'));

                return [
                    'name' => $stageName,
                    'options' => [
                        'git-url' => data_get($this->configurations, 'projectInfo.git_url'),
                        'base-dir-pattern' => data_get($stageConfig, 'options.base_dir_pattern'),
                        'bin-composer' => data_get($stageConfig, 'options.bin_composer'),
                        'bin-php' => data_get($stageConfig, 'options.bin_php'),
                    ],
                    ...collect($this->accessInfo[$stageName])
                        ->only(['database', 'mail', 'server'])
                        ->all(),
                ];
            })
            ->values()
            ->toArray();

        return [
            'version' => self::YML_CONFIG_VERSION,
            'git-lab' => [
                'project' => [
                    'token' => data_get($this->configurations, 'projectInfo.token'),
                    'project-id' => $this->retrieveProjectId(),
                    'domain' => data_get($this->configurations, 'projectInfo.domain'),
                ],
            ],
            'stages' => $stages,
        ];
    }

    public function parseInputForAccessPayload(?string $stageName, ?string $accessInput): self
    {
        $this->notResolved[$stageName] = [];

        $detected = [
            'database' => false,
            'mail' => false,
            'server' => false,
        ];

        $this->accessInfo[$stageName] = collect(explode("\n", $accessInput))
            ->chunkWhile(fn ($line) => !empty(trim($line)))
            ->mapWithKeys(function (Collection $lines, int $chunkIndex) use ($stageName, &$detected) {
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

                $this->notResolved[$stageName][] = [
                    'chunk' => $chunkIndex + 1,
                    'lines' => $lines,
                ];

                return [
                    'skip' => null,
                ];
            })
            ->filter()
            ->toArray();

        return $this;
    }

    public function makeDeployPrepareYmlFile(): string
    {
        $contents = $this->contentForDeployPrepareConfig();

        $projectId = $this->retrieveProjectId();
        $file = "{$projectId}/deploy-prepare.yml";

        $filesystem = Storage::disk('local');

        $filesystem->put($file, $contents);

        return $filesystem->path($file);
    }

    public function makeDeployerPhpFile(?string $stageName = null): string
    {
        $contents = $this->contentForDeployerScript($stageName);

        $projectId = $this->retrieveProjectId();
        $file = "{$projectId}/deploy.php";

        $filesystem = Storage::disk('local');

        $filesystem->put($file, $contents);

        return $filesystem->path($file);
    }

    public function contentForDeployPrepareConfig(): string
    {
        return Yaml::dump($this->buildDeployPrepareConfig(), 4, 2);
    }

    public function contentForDeployerScript(?string $stageName = null): string
    {
        $stageConfig = $this->findStageConfig($stageName);

        $renderVariables = !is_null($stageName) && is_array($stageConfig);

        return view('deployer', [
            'applicationName' => data_get($this->configurations, 'projectInfo.name'),
            'githubOathToken' => data_get($this->configurations, 'projectInfo.github_token', 'xxxx_githubOathToken_xxxx'),
            'renderVariables' => $renderVariables,
            ...($renderVariables ? [
                'CI_REPOSITORY_URL' => data_get($this->configurations, 'projectInfo.git_url'),
                'CI_COMMIT_REF_NAME' => $stageName,
                'BIN_PHP' => data_get($stageConfig, 'options.bin_php'),
                'BIN_COMPOSER' => data_get($stageConfig, 'options.bin_composer'),
                'DEPLOY_SERVER' => $server = data_get($stageConfig, 'accessInfo.server.host'),
                'DEPLOY_USER' => $user = data_get($stageConfig, 'accessInfo.server.login'),
                'DEPLOY_BASE_DIR' => Str::replace(
                    ['{{HOST}}', '{{USER}}'],
                    [$server, $user],
                    data_get($stageConfig, 'options.base_dir_pattern')
                ),
                'SSH_PORT' => data_get($stageConfig, 'accessInfo.server.port', 22),
            ] : []),
        ])->render();
    }

    private function findStageConfig(?string $stageName): ?array
    {
        return collect(data_get($this->configurations, 'stages'))
            ->first(fn ($stage) => data_get($stage, 'name') === $stageName);
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

    private function retrieveProjectId(): mixed
    {
        return data_get($this->configurations, 'projectInfo.project_id');
    }
}
