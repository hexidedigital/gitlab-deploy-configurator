<?php

namespace App\Domains\DeployConfigurator;

use App\Domains\DeployConfigurator\ContentGenerators\DeployerPhpGenerator;
use App\Domains\DeployConfigurator\Data\ProjectDetails;
use App\Domains\DeployConfigurator\Data\Stage\StageInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

class DeployConfigBuilder
{
    private const YML_CONFIG_VERSION = 1.4;

    private ProjectDetails $projectDetails;
    private Collection $stagesList;

    public function __construct(
        private readonly AccessParser $accessParser,
    ) {
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
        return $this->accessParser->getAccessInfo($stageName);
    }

    public function getNotResolved(?string $stageName = null): array
    {
        return $this->accessParser->getNotResolved($stageName);
    }

    public function processStages(?string $forceParseStageName = null): array
    {
        return $this->stagesList
            ->filter(function (array $stageConfig) use ($forceParseStageName) {
                return ($stageConfig['can_be_parsed'] ?? false)
                    || !empty($stageConfig['name'])
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

                        // additional keys, not used in yaml file
                        'home-folder' => data_get($stageConfig, 'options.home_folder'),
                        'ssh' => data_get($stageConfig, 'options.ssh'),
                        'bash_aliases' => data_get($stageConfig, 'options.bash_aliases'),
                    ],
                    ...collect($this->getAccessInfo($stageName))
                        ->only(['database', 'mail', 'server'])
                        ->all(),
                ];
            })
            ->values()
            ->toArray();
    }

    public function buildDeployPrepareConfig(?string $forceParseStageName = null): array
    {
        return [
            'version' => self::YML_CONFIG_VERSION,
            'git-lab' => [
                'project' => [
                    'token' => $this->projectDetails->token,
                    'project-id' => $this->retrieveProjectId(),
                    'domain' => $this->projectDetails->domain,
                ],
            ],
            'stages' => collect($this->processStages($forceParseStageName))->map(function ($stage) {
                $stage['options'] = collect($stage['options'])->except([
                    // additional keys, not used in yaml file
                    'home-folder',
                    'ssh',
                    'bash_aliases',
                ])->all();

                return $stage;
            })->all(),
        ];
    }

    public function parseInputForAccessPayload(?string $stageName, ?string $accessInput): self
    {
        if ($stageName) {
            $this->accessParser->parseInputForAccessPayload($stageName, $accessInput);
        }

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
        $stagePayload = $this->findStageConfig($stageName);

        $stageConfig = $stagePayload ? StageInfo::makeFromArray([
            'name' => $stagePayload['name'],
            'options' => $stagePayload['options'],
            ...$stagePayload['accessInfo'],
        ]) : null;

        return (new DeployerPhpGenerator($this->projectDetails))->render($stageConfig, $generateWithVariables);
    }

    public function findStageConfig(?string $stageName): ?array
    {
        return $this->stagesList
            ->first(fn ($stage) => data_get($stage, 'name') === $stageName);
    }

    private function retrieveProjectId(): int
    {
        return $this->projectDetails->project_id;
    }
}
