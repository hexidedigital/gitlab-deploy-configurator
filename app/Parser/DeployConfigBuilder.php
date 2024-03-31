<?php

namespace App\Parser;

use App\GitLab\Deploy\Data\ProjectDetails;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class DeployConfigBuilder
{
    private const YML_CONFIG_VERSION = 1.4;

    private ProjectDetails $projectDetails;
    private Collection $stagesList;

    private array $accessInfo = [];
    private array $notResolved = [];

    public function __construct()
    {
        $this->stagesList = new Collection();
    }

    public function parseConfiguration(array $configurations): void
    {
        $this->setStagesList($configurations['stages']);

        $this->setProjectDetails(ProjectDetails::makeFromArray($configurations['projectInfo']));
    }

    public function setStagesList(array $stagesList): void
    {
        $this->stagesList = collect($stagesList);
    }

    public function setProjectDetails(ProjectDetails $projectDetails): void
    {
        $this->projectDetails = $projectDetails;
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

    public function buildDeployPrepareConfig(?string $forceParseStageName = null): array
    {
        $stages = $this->stagesList
            ->filter(function (array $stageConfig) use ($forceParseStageName) {
                return ($stageConfig['can_be_parsed'] ?? false)
                    || $forceParseStageName === $stageConfig['name'];
            })
            ->map(function (array $stageConfig) {
                $stageName = data_get($stageConfig, 'name');

                $this->parseInputForAccessPayload($stageName, data_get($stageConfig, 'access_input'));

                return [
                    'name' => $stageName,
                    'options' => [
                        'git-url' => $this->projectDetails->git_url,
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
                    'token' => $this->projectDetails->token,
                    'project-id' => $this->retrieveProjectId(),
                    'domain' => $this->projectDetails->domain,
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

    public function makeDeployerPhpFile(?string $stageName = null, bool $generateWithVariables = false): string
    {
        $contents = $this->contentForDeployerScript($stageName, $generateWithVariables);

        $projectId = $this->retrieveProjectId();
        $file = "{$projectId}/deploy.php";

        $filesystem = Storage::disk('local');

        $filesystem->put($file, $contents);

        return $filesystem->path($file);
    }

    public function contentForDeployPrepareConfig(?string $forceParseStageName = null): string
    {
        return Yaml::dump($this->buildDeployPrepareConfig($forceParseStageName), 4, 2);
    }

    public function contentForDeployerScript(?string $stageName = null, bool $generateWithVariables = false): string
    {
        $stageConfig = $this->findStageConfig($stageName);

        $renderVariables = !is_null($stageName) && is_array($stageConfig) && $generateWithVariables;

        return view('deployer', [
            'applicationName' => $this->projectDetails->name,
            'githubOathToken' => config('services.gitlab.deploy_token'),
            'renderVariables' => $renderVariables,
            ...($renderVariables ? [
                'CI_REPOSITORY_URL' => $this->projectDetails->git_url,
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
        return $this->stagesList
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

    private function retrieveProjectId(): int
    {
        return $this->projectDetails->project_id;
    }
}
