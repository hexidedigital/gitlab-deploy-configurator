<?php

namespace App\Jobs;

use App\Filament\Dashboard\Pages\DeployConfigurator\WithGitlab;
use App\GitLab\Data\ProjectData;
use App\GitLab\Deploy\Data\CiCdOptions;
use App\GitLab\Deploy\Data\ProjectDetails;
use App\Models\User;
use App\Parser\DeployConfigBuilder;
use Exception;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use GrahamCampbell\GitLab\GitLabManager;
use HexideDigital\GitlabDeploy\DeployerState;
use HexideDigital\GitlabDeploy\Exceptions\GitlabDeployException;
use HexideDigital\GitlabDeploy\Gitlab\Tasks\GitlabVariablesCreator;
use HexideDigital\GitlabDeploy\Gitlab\Variable;
use HexideDigital\GitlabDeploy\Helpers\Builders\ConfigurationBuilder;
use HexideDigital\GitlabDeploy\Helpers\Replacements;
use HexideDigital\GitlabDeploy\Loggers\ConsoleLogger;
use HexideDigital\GitlabDeploy\Loggers\FileLogger;
use HexideDigital\GitlabDeploy\Loggers\LoggerBag;
use HexideDigital\GitlabDeploy\PipeData;
use HexideDigital\GitlabDeploy\ProcessExecutors\BasicExecutor;
use HexideDigital\GitlabDeploy\Tasks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ConfigureRepositoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use WithGitlab;

    protected GitLabManager $gitLabManager;
    protected ProjectData $projectData;
    protected string $stageName;
    private LoggerBag $logger;

    public function __construct(
        public int $userId,
        public ProjectDetails $projectDetails,
        public CiCdOptions $ciCdOptions,
        public array $deployConfigurations,
    ) {
    }

    /**
     * @throws CircularDependencyException
     * @throws BindingResolutionException
     * @throws GitlabDeployException
     * @throws Exception
     */
    public function handle(): void
    {
        // todo - mock project id
        $projectId = $this->projectDetails->project_id;
//        $projectId = 689; // select 'laravel 11 playground'

        $this->projectData = $this->findProject($projectId);

        // todo - dev prepare
//        $this->cleanUpRepository();

        $tasks = [
//            Tasks\GenerateSshKeysOnLocalhost::class, // done
            Tasks\CopySshKeysOnRemoteHost::class, // use ssh package
            Tasks\GenerateSshKeysOnRemoteHost::class, // use ssh package

//            Tasks\CreateProjectVariablesOnGitlab::class, // done
            Tasks\AddGitlabToKnownHostsOnRemoteHost::class, // use ssh package
//            Tasks\SaveInitialContentOfDeployFile::class, // -

//            Tasks\PutNewVariablesToDeployFile::class, // -
            Tasks\PrepareAndCopyDotEnvFileForRemote::class, // use ssh package

//            Tasks\RunFirstDeployCommand::class, // -
//            Tasks\RollbackDeployFileContent::class, // -
            Tasks\InsertCustomAliasesOnRemoteHost::class, // use ssh package

            Tasks\HelpfulSuggestion::class,
        ];


        $deployFolder = Storage::disk('local')->path('deploy_folder/project_' . $projectId);

        File::ensureDirectoryExists($deployFolder);
        File::ensureDirectoryExists("$deployFolder/deployer");
        File::ensureDirectoryExists("$deployFolder/ssh");

        config([
            'gitlab-deploy.working-dir' => "$deployFolder/deployer",
            'gitlab-deploy.ssh' => [
                'key_name' => 'id_rsa',
                'folder' => "$deployFolder/ssh",
            ],
            'tasks' => $tasks,
        ]);

        // prepare configurations
        $deployConfigBuilder = new DeployConfigBuilder();
        $deployConfigBuilder->setStagesList($this->deployConfigurations['stages']);
        $deployConfigBuilder->setProjectDetails($this->projectDetails);

        $state = new DeployerState();
        $builder = app(ConfigurationBuilder::class);
        $configurations = $builder->build($this->deployConfigurations);
        $state->setConfigurations($configurations);

        $this->createLoggers();

        foreach ($this->deployConfigurations['stages'] as $stage) {
            $this->stageName = $stageName = $stage['name'];

            $state->setStage($configurations->stageBag->get($stageName));

            $state->setupReplacements();
            $state->setupGitlabVariables();

            $pipeData = $this->preparePipeData(count($tasks), $state);

            // generate ssh keys
            $identityFilePath = $state->getReplacements()->replace('{{IDENTITY_FILE}}');

            if (!$this->isSshFilesExits($state->getReplacements())) {
                $command = ['ssh-keygen', '-t', 'rsa', '-N', '""', "-f", $identityFilePath];

                $status = (new Process($command, $deployFolder))
                    ->setTimeout(0)
                    ->run(function ($type, $output) {
                        dump($output);
                    });
                dump(['ssh-keygen' => $status]);

                if ($status !== 0) {
                    throw new Exception('failed');
                }
            }

            $newVariables = [
                new Variable(
                    key: 'SSH_PRIVATE_KEY',
                    scope: $state->getStage()->name,
                    value: File::get($identityFilePath) ?: '',
                ),
                /*todo - mock*/
                new Variable(
                    key: 'SSH_PUB_KEY',
                    scope: $state->getStage()->name,
                    value: File::get("{$identityFilePath}.pub"),
                ),
            ];

            if ($this->ciCdOptions->withDisableStages()) {
                if ($this->ciCdOptions->isStagesDisabled('prepare')) {
                    $newVariables[] = new Variable(
                        key: 'CI_COMPOSER_STAGE',
                        scope: '*',
                        value: 0,
                    );
                }
                if ($this->ciCdOptions->isStagesDisabled('build')) {
                    $newVariables[] = new Variable(
                        key: 'CI_BUILD_STAGE',
                        scope: '*',
                        value: 0,
                    );
                }
            }

            // ... remote commands with ssh ...

            foreach ($newVariables as $variable) {
                $state->getGitlabVariablesBag()->add($variable);
            }

            // create variables for stage
            $gitlabVariablesCreator = new GitlabVariablesCreator($this->getGitLabManager());
            $gitlabVariablesCreator
                ->setProject($state->getConfigurations()->project)
                ->setVariableBag($state->getGitlabVariablesBag());

            $gitlabVariablesCreator->execute();

            if ($fails = $gitlabVariablesCreator->getFailMassages()) {
                dump($fails);
            }

            // push and create files in repository
            $this->createCommitWithConfigFiles($stageName, $deployConfigBuilder);

            // todo - process only one stage
            break;
        }

        // done notification
        $this->sendSuccessNotification();

        /*todo - mock*/
//        $this->release(20);
    }

    public function failed(Exception $exception): void
    {
        $this->fail($exception);
        /*todo - mock*/
//        $this->release(20);
    }

    public function tries(): int
    {
        return 120;
    }

    public function sendSuccessNotification(): void
    {
        dump("Repository '{$this->projectData->name}' configured successfully");

//        return;

        Notification::make()
            ->success()
            ->icon('heroicon-o-rocket-launch')
            ->title("Repository '{$this->projectData->name}' configured successfully")
            ->actions([
                Action::make('view')
                    ->label('View in GitLab')
                    ->icon('feathericon-gitlab')
                    ->button()
                    ->url("https://gitlab.hexide-digital.com/{$this->projectData->path_with_namespace}/-/pipelines", shouldOpenInNewTab: true),
            ])
            ->sendToDatabase(User::find($this->userId));
    }

    protected function cleanUpRepository(): void
    {
        // delete variables
        collect($this->getGitLabManager()->projects()->variables($this->projectData->id))
            // remove all variables except the ones with environment_scope = '*'
            ->reject(fn (array $variable) => str($variable['environment_scope']) == '*')
            ->each(fn (array $variable) => $this->getGitLabManager()->projects()->removeVariable($this->projectData->id, $variable['key'], [
                'filter' => ['environment_scope' => $variable['environment_scope']],
            ]));

        // delete test branches
        collect($this->getGitLabManager()->repositories()->branches($this->projectData->id))
            ->reject(fn (array $branch) => str($branch['name'])->startsWith(['develop']))
            ->filter(fn (array $branch) => str($branch['name'])->startsWith(['test', 'dev']))
            ->each(fn (array $branch) => $this->getGitLabManager()->repositories()->deleteBranch($this->projectData->id, $branch['name']));
    }

    protected function createCommitWithConfigFiles(string $stageName, DeployConfigBuilder $deployConfigBuilder): void
    {
        $defaultBranch = $this->projectData->default_branch;

        // https://docs.gitlab.com/ee/api/commits.html#create-a-commit-with-multiple-files-and-actions
        $commit = $this->getGitLabManager()->repositories()->createCommit($this->projectData->id, [
            "branch" => $stageName,
            "start_branch" => $defaultBranch,
            "commit_message" => "Configure deployment " . now()->format('H:i:s'),
            "author_name" => "DeployHelper",
            "author_email" => "deploy-helper@hexide-digital.com",
            "actions" => [
                [
                    "action" => "create",
                    "file_path" => ".gitlab-ci.yml",
                    "content" => base64_encode(
                        view('gitlab-ci-yml', [
                            'templateVersion' => $this->ciCdOptions->template_version,
                            'nodeVersion' => $this->ciCdOptions->node_version,
                        ])->render()
                    ),
                    "encoding" => "base64",
                ],
                [
                    "action" => "create",
                    "file_path" => "deploy.php",
                    "content" => base64_encode($deployConfigBuilder->contentForDeployerScript($stageName)),
                    "encoding" => "base64",
                ],
            ],
        ]);

        dump($commit);
    }

    protected function getGitLabManager(): GitLabManager
    {
        if (isset($this->gitLabManager)) {
            return $this->gitLabManager;
        }

        return tap($this->gitLabManager = app(GitLabManager::class), function (GitLabManager $manager) {
            $this->authenticateGitlabManager(
                $manager,
                $this->projectDetails->token,
                $this->projectDetails->domain,
            );
        });
    }

    protected function createLoggers(): void
    {
        $this->logger = new LoggerBag();

        $dir = str(config('gitlab-deploy.working-dir'))->finish('/')->append('logs');
        $this->logger->setFileLogger(new FileLogger((string)$dir));
        $this->logger->setConsoleLogger(new ConsoleLogger());

        $this->logger->init();
    }

    protected function preparePipeData(int $tasksToExecute, DeployerState $state): PipeData
    {
        $executor = new BasicExecutor(
            $this->logger,
            $state->getReplacements(),
        );

        return new PipeData(
            $state,
            $this->logger,
            $executor,
            null,
            $tasksToExecute,
        );
    }

    private function isSshFilesExits(Replacements $replacements): bool
    {
        return File::exists($replacements->replace('{{IDENTITY_FILE}}'))
            || File::exists($replacements->replace('{{IDENTITY_FILE_PUB}}'));
    }
}
