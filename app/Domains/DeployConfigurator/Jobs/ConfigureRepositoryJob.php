<?php

namespace App\Domains\DeployConfigurator\Jobs;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\ContentGenerators\DeployerPhpGenerator;
use App\Domains\DeployConfigurator\ContentGenerators\GitlabCiCdYamlGenerator;
use App\Domains\DeployConfigurator\Data\CiCdOptions;
use App\Domains\DeployConfigurator\Data\ProjectDetails;
use App\Domains\DeployConfigurator\Data\Stage\StageInfo;
use App\Domains\DeployConfigurator\DeployConfigBuilder;
use App\Domains\DeployConfigurator\DeployerState;
use App\Domains\DeployConfigurator\Events\DeployConfigurationJobFailedEvent;
use App\Domains\DeployConfigurator\Helpers\Builders\ConfigurationBuilder;
use App\Domains\DeployConfigurator\LogWriter;
use App\Domains\GitLab\Data\ProjectData;
use App\Domains\GitLab\GitLabService;
use App\Models\DeployProject;
use App\Models\User;
use App\Notifications\UserTelegramNotification;
use App\Settings\GeneralSettings;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Exception;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use HexideDigital\GitlabDeploy\Gitlab\Tasks\GitlabVariablesCreator;
use HexideDigital\GitlabDeploy\Gitlab\Variable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use NotificationChannels\Telegram\TelegramMessage;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ConfigureRepositoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** moik.o / laravel 11 playground */
    public const TEST_PROJECT = 689;
    private array $testingProjects = [
        ConfigureRepositoryJob::TEST_PROJECT,
    ];

    // job configurations
    public int $timeout = 120;
    public int $tries = 2;
    public bool $deleteWhenMissingModels = true;

    // uses only in job
    private User $user;

    // across tasks
    private ProjectData $gitlabProject;
    private string $deployFolder;
    private DeployerState $state;
    private Filesystem|FilesystemAdapter $remoteFilesystem;
    private LogWriter $logWriter;
    private GitLabService $gitLabService;
    private ProjectDetails $projectDetails;
    private CiCdOptions $ciCdOptions;
    private array $deployConfigurations;
    private StageInfo $currentStageInfo;

    public function __construct(
        public int $userId,
        public DeployProject $deployProject,
    ) {
    }

    public function handle(DeployConfigBuilder $deployConfigBuilder): void
    {
        $this->setup();

        $projectId = $this->projectDetails->project_id;

        $this->logWriter->info('Start configuring repository for project');

        $project = $this->gitLabService->findProject($projectId);
        if (is_null($project)) {
            $this->fail(new Exception('Project not found or access denied'));

            return;
        }

        $this->gitlabProject = $project;

        $this->user->notify(new UserTelegramNotification(TelegramMessage::create()->line("We started configuring '{$this->gitlabProject->name}' repository...")));

        (new CleanUpRepositoryForTestingAction(
            $this->gitLabService,
            $this->logWriter,
            $this->gitlabProject,
        ))->isTestingProject($this->isTestingProject())->execute();

        $this->prepareWorkingDirectoryForProjectSetup();

        $this->logWriter->info('Prepare general configurations...');

        // prepare general configurations
        $stages = $this->deployProject->deploy_payload['stages'];

        $deployConfigBuilder->setStagesList($stages);
        $deployConfigBuilder->setProjectDetails($this->projectDetails);

        $deployConfigurations = $deployConfigBuilder->buildDeployPrepareConfig();
        $deployConfigurations['stages'] = $stages;

        $this->state = $this->makeDeployState($deployConfigurations);

        $this->logWriter->info('Processing stages...');

        foreach ($stages as $stage) {
            $this->currentStageInfo = StageInfo::makeFromArray($stage);

            $this->deployProject->update([
                'current_step' => 'processing',
                'status' => 'processing stage ' . $this->currentStageInfo->name,
            ]);

            $this->logWriter->getLogger()->withContext(['stage' => $this->currentStageInfo->name]);

            $this->logWriter->info("Processing stage: {$this->currentStageInfo->name}");

            // prepare configurations for stage
            $this->selectStage();

            try {
                $this->initSftpClient();

                $this->executeTasksForStage();

                $this->logWriter->info("Stage '{$this->currentStageInfo->name}' processed");

                $this->sendSuccessNotification();

                $this->deployProject->update([
                    'current_step' => 'done',
                    'status' => 'finished',
                    'logs' => $this->logWriter->getLogBag(),
                    'finished_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $this->logWriter->error('Failed to process stage ' . $this->currentStageInfo->name, [
                    'exception' => $exception->getMessage(),
                ]);

                $this->deployProject->increment('fail_counts');
                $this->deployProject->update([
                    'status' => 'exception failed',
                    'logs' => $this->logWriter->getLogBag(),
                    'failed_at' => now(),
                    'exception' => $exception->getMessage(),
                ]);

                $this->failed($exception);

                throw $exception;
            }
        }
    }

    protected function setup(): void
    {
        $this->projectDetails = ProjectDetails::makeFromArray($this->deployProject->deploy_payload['projectDetails']);
        $this->ciCdOptions = CiCdOptions::makeFromArray($this->deployProject->deploy_payload['ciCdOptions']);

        $this->user = User::findOrFail($this->userId);

        $this->gitLabService = resolve(GitLabService::class)->authenticateUsing($this->projectDetails->token);

        $this->setupLogger();
    }

    protected function setupLogger(): void
    {
        $this->logWriter = new LogWriter();
        $this->logWriter->getLogger()->withContext([
            'project_id' => $this->projectDetails->project_id,
            'user_id' => $this->userId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->setup();

        $this->logWriter->error('Failed configuring repository', [
            'exception' => $exception->getMessage(),
        ]);

        report($exception);

        $this->deployProject->increment('fail_counts');
        $this->deployProject->update([
            'status' => 'failed',
            'exception' => $exception->getMessage(),
        ]);

        DeployConfigurationJobFailedEvent::dispatch($this->user, $this->gitlabProject, $exception);
    }

    private function sendSuccessNotification(): void
    {
        $settings = app(GeneralSettings::class);
        $domain = str(app(GeneralSettings::class)->gitlabDomain)->replace(['https://', 'http://'], '');
        $gitlabPipelineUrl = "https://{$domain}/{$this->gitlabProject->path_with_namespace}/-/pipelines";

        $this->user->notify(
            new UserTelegramNotification(
                TelegramMessage::create()
                    ->line('Your repository has been configured successfully.')
                    ->line('Project: ' . $this->gitlabProject->name)
                    ->line('Stage: ' . $this->currentStageInfo->name)
                    ->line('Time: ' . now()->timezone('Europe/Kiev')->format('Y-m-d H:i:s'))
                    ->button("Open {$this->state->getStage()->server->domain}", $this->state->getStage()->server->domain)
            )
        );

        if ($this->user->canReceiveTelegramMessage()) {
            TelegraphChat::query()
                ->where('telegraph_bot_id', TelegraphBot::where('name', $settings->mainTelegramBot)->value('id'))
                ->where('chat_id', $this->user->telegram_id)
                ->first()
                ->message(
                    "We have finished configuring '{$this->gitlabProject->name}' repository.\n" .
                    'Time elapsed: ' . now()->shortAbsoluteDiffForHumans(Carbon::parse($this->deployProject->deploy_payload['openedAt']), parts: 2)
                )
                ->keyboard(Keyboard::make()->button('GitLab pipeline')->url($gitlabPipelineUrl))
                ->send();
        }

        $this->logWriter->info("Repository '{$this->gitlabProject->name}' configured successfully");

        Notification::make()
            ->success()
            ->icon('heroicon-o-rocket-launch')
            ->title("Repository '{$this->gitlabProject->name}'")
            ->body("Stage '{$this->currentStageInfo->name}' for repository processed successfully")
            ->actions([
                Action::make('view')
                    ->label('View in GitLab')
                    ->icon('feathericon-gitlab')
                    ->button()
                    ->url($gitlabPipelineUrl, shouldOpenInNewTab: true),

                Action::make('mark_as_read')
                    ->label('Mark as read')
                    ->markAsRead(),
            ])->sendToDatabase($this->user);
    }

    private function executeTasksForStage(): void
    {
        // task 1 - generate ssh keys on localhost
        $this->logWriter->info('Step 1: Generating SSH keys on localhost');
        $this->deployProject->update(['current_step' => 'step1']);
        $this->taskGenerateSshKeysOnLocalhost();

        // task 2 - copy ssh keys on remote host
        // get content pub key and put to auth_keys on server
        $this->logWriter->info('Step 2: Copying SSH keys on remote host');
        $this->deployProject->update(['current_step' => 'step2']);
        $this->taskCopySshKeysOnRemoteHost();

        // task 3 - generate ssh keys on remote host
        // fetch existing keys, or generate new one locally (specify user and login) or on remove (need execute)
        $this->logWriter->info('Step 3: Generating SSH keys on remote host');
        $this->deployProject->update(['current_step' => 'step3']);
        $this->taskGenerateSshKeysOnRemoteHost();

        // task 4 - create project variables on gitlab
        // create and configure gitlab variables
        $this->logWriter->info('Step 4: Creating project variables on GitLab');
        $this->deployProject->update(['current_step' => 'step4']);
        $this->taskCreateProjectVariables();

        // task 5 - add gitlab to known hosts on remote host
        // append content to known_hosts file
        $this->logWriter->info('Step 5: Adding GitLab to known hosts on remote host');
        $this->deployProject->update(['current_step' => 'step5']);
        $this->taskAddGitlabToKnownHostsOnRemoteHost();

        // task 6 - prepare and copy dot env file for remote
        // copy file to remote (create folder)
        $this->logWriter->info('Step 6: Preparing and copying .env file for remote');
        $this->deployProject->update(['current_step' => 'step6']);
        $this->taskPrepareAndCopyDotEnvFileForRemote();

        // task 7 - push branch and trigger pipeline (run first deploy command)
        // create deploy branch with new files in repository
        $this->logWriter->info('Step 7: Creating commit with config files');
        $this->deployProject->update(['current_step' => 'step7']);
        $this->taskCreateCommitWithConfigFiles();

        // task 8 - insert custom aliases on remote host
        // create or append file content
        $this->logWriter->info('Step 8: Inserting custom aliases on remote host');
        $this->deployProject->update(['current_step' => 'step8']);
        $this->taskInsertCustomAliasesOnRemoteHost();
    }

    private function isFrontend(): bool
    {
        return once(function () {
            return (new CiCdTemplateRepository())->getTemplateGroup($this->ciCdOptions->template_group)->isFrontend();
        });
    }

    private function taskCreateCommitWithConfigFiles(): void
    {
        // https://docs.gitlab.com/ee/api/commits.html#create-a-commit-with-multiple-files-and-actions
        $actions = collect([
            [
                // "action" => "create",
                "file_path" => ".gitlab-ci.yml",
                "content" => (new GitlabCiCdYamlGenerator($this->ciCdOptions, $this->projectDetails))->render(),
            ],
            [
                // "action" => "create",
                "file_path" => "deploy.php",
                "content" => (new DeployerPhpGenerator($this->projectDetails))->render($this->currentStageInfo),
            ],
        ])->map(function (array $action) {
            // skip deploy.php for frontend projects
            if ($this->isFrontend() && $action['file_path'] == 'deploy.php') {
                return null;
            }

            $generatedContent = $action['content'];

            // check if file exists
            $currentContent = $this->gitLabService->getFileContent($this->gitlabProject, $action['file_path']);

            // if current content is same with generated - skip
            if ($currentContent === $generatedContent) {
                return null;
            }

            // if file does not exist, create it
            if (empty($currentContent)) {
                $action['action'] = 'create';
            } else {
                // if file exists and new content, update it
                $action['action'] = 'update';
            }

            $action['content'] = base64_encode($generatedContent);
            $action['encoding'] = 'base64';

            return $action;
        })->values()->filter();

        // if files without changes, just create branch
        if ($actions->isEmpty()) {
            $this->logWriter->info('No changes in config files');

            $this->logWriter->info("Creating branch '{$this->currentStageInfo->name}' for deployment");

            $branch = $this->gitLabService->gitLabManager()->repositories()
                ->createBranch($this->gitlabProject->id, $this->currentStageInfo->name, $this->gitlabProject->default_branch);

            $this->logWriter->debug('branch response', ['branch' => $branch]);

            return;
        }

        // we try to use default branch for start, but when deploy branch exists, we use it as start
        $startBranch = $this->gitlabProject->default_branch;

        $branches = $this->gitLabService->gitLabManager()->repositories()->branches($this->gitlabProject->id);
        $isBranchExists = collect($branches)->map(fn ($branch) => $branch['name'])->contains($this->currentStageInfo->name);
        if ($isBranchExists) {
            $startBranch = $this->currentStageInfo->name;

            $this->logWriter->debug('Branch already exists, using it as default', ['branch' => $startBranch]);
        }

        // otherwise, create commit with new files
        $commit = $this->gitLabService->gitLabManager()->repositories()->createCommit($this->gitlabProject->id, [
            "branch" => $this->currentStageInfo->name,
            "start_branch" => $startBranch,
            "commit_message" => "Configure deployment 🚀",
            "author_name" => "Deploy Configurator Bot",
            "author_email" => "deploy-configurator-bot@hexide-digital.com",
            "actions" => $actions->all(),
        ]);

        $this->logWriter->debug('commit response', ['commit' => $commit]);
    }

    private function makeDeployState(array $deployConfigurations): DeployerState
    {
        // config([
        //    'gitlab-deploy.working-dir' => "{$this->deployFolder}/deployer",
        //    'gitlab-deploy.ssh' => [
        //        'key_name' => 'id_rsa',
        //        'folder' => "{$this->deployFolder}/ssh",
        //    ],
        // ]);

        $state = new DeployerState();
        $configurations = app(ConfigurationBuilder::class)->build($deployConfigurations);
        $state->setConfigurations($configurations);

        return $state;
    }

    private function selectStage(): void
    {
        $this->state->setStage($this->state->getConfigurations()->stageBag->get($this->currentStageInfo->name));
        $this->state->setupReplacements();
        $this->state->setupGitlabVariables($this->isFrontend());

        // rewrite replacements
        $this->state->getReplacements()->merge([
            // rewrite working directory
            'PROJ_DIR' => $this->deployFolder,

            'IDENTITY_FILE' => "{$this->deployFolder}/local_ssh/id_rsa",
            'IDENTITY_FILE_PUB' => "{$this->deployFolder}/local_ssh/id_rsa.pub",
        ]);

        // automatically enable CI/CD
        $variable = new Variable(
            key: 'CI_ENABLED',
            scope: $this->currentStageInfo->name,
            value: '1',
        );
        $this->state->getGitlabVariablesBag()->add($variable);
    }

    private function initSftpClient(): void
    {
        $this->logWriter->info('Initializing SFTP client');

        $variables = $this->state->getReplacements();

        $sshOptions = $this->currentStageInfo->options->ssh;

        $host = $variables->get('DEPLOY_SERVER'); // ip or domain
        $login = $variables->get('DEPLOY_USER');

        $useCustomSshKeys = $sshOptions->useCustomSshKey;
        if ($useCustomSshKeys) {
            $key = PublicKeyLoader::load(
                key: $privateKey = $sshOptions->privateKey,
                password: $sshOptions->privateKeyPassword ?: false
            );

            File::ensureDirectoryExists(File::dirname($pathKeyPath = "{$this->deployFolder}/local_ssh/id_rsa"));
            File::put($pathKeyPath, $privateKey);
        } else {
            $key = $variables->get('DEPLOY_PASS');
        }

        $this->logWriter->info('Checking connection to remote host');
        $ssh = new SSH2($host, $port = $variables->get('SSH_PORT') ?: 22);
        if (!$ssh->login($login, $key)) {
            $this->logWriter->error('Failed to connect with ssh', compact(['login', 'host', 'port', 'useCustomSshKeys']));

            throw new RuntimeException('SSH Login failed');
        }
        $this->logWriter->info('Connection to remote host established');

        $root = $this->currentStageInfo->options->homeFolder;
        $this->logWriter->debug("Using '{$root}' as root folder for sftp");

        // https://laravel.com/docs/filesystem#sftp-driver-configuration
        // https://flysystem.thephpleague.com/docs/adapter/sftp-v3
        $this->remoteFilesystem = Storage::build([
            'driver' => 'sftp',
            'host' => $host,

            // Settings for basic authentication...
            'username' => $login,
            ...(!$useCustomSshKeys ? [
                'password' => $variables->get('DEPLOY_PASS'),
            ] : [
                // Settings for SSH key based authentication with encryption password...
                'privateKey' => $sshOptions->privateKey,
                'passphrase' => $sshOptions->privateKeyPassword,
            ]),

            // Settings for file / directory permissions...
            'visibility' => 'public', // `private` = 0600, `public` = 0644
            'directory_visibility' => 'public', // `private` = 0700, `public` = 0755

            // Optional SFTP Settings...
            // 'maxTries' => 4,
            'port' => intval($port),
            'root' => $root,
            'timeout' => 30,
            // 'useAgent' => true,
        ]);
    }

    private function taskGenerateSshKeysOnLocalhost(): void
    {
        $sshOptions = $this->currentStageInfo->options->ssh;
        $useCustomSshKeys = $sshOptions->useCustomSshKey;

        if ($useCustomSshKeys) {
            $identityFileContent = $sshOptions->privateKey ?: '';
        } else {
            File::ensureDirectoryExists("{$this->deployFolder}/local_ssh");

            $identityFilePath = $this->state->getReplacements()->get('IDENTITY_FILE');
            $isIdentityKeyExists = File::exists($identityFilePath) || File::exists("{$identityFilePath}.pub");
            if (!$isIdentityKeyExists) {
                $this->generateIdentityKey($identityFilePath, $this->user->email);
            }

            $identityFileContent = File::get($identityFilePath) ?: '';
        }

        if ($identityFileContent === '') {
            $this->logWriter->warning('Empty SSH private key content. Skip creating "SSH_PRIVATE_KEY" variable');

            return;
        }

        // update private key variable
        $variable = new Variable(
            key: 'SSH_PRIVATE_KEY',
            scope: $this->state->getStage()->name,
            value: rtrim($identityFileContent),
        );

        $this->state->getGitlabVariablesBag()->add($variable);
    }

    private function prepareWorkingDirectoryForProjectSetup(): void
    {
        $this->logWriter->debug('Preparing working directory for project setup');

        $this->deployFolder = Storage::disk('local')->path('deploy_folder/project_' . $this->gitlabProject->id);

        File::ensureDirectoryExists($this->deployFolder);

        $this->logWriter->debug('Working directory prepared');
    }

    private function generateIdentityKey(string $identityFilePath, string $comment): void
    {
        File::ensureDirectoryExists(File::dirname($identityFilePath));

        $command = ['ssh-keygen', '-t', 'rsa', '-N', '', "-f", $identityFilePath, '-C', $comment];

        $status = (new Process($command, $this->deployFolder))
            ->setTimeout(0)
            ->run(function ($type, $output) {
                $this->logWriter->debug($output);
            });

        if ($status !== 0) {
            throw new RuntimeException('failed');
        }
    }

    private function taskCreateProjectVariables(): void
    {
        $variableBag = $this->state->getGitlabVariablesBag();

        $templateRepository = new CiCdTemplateRepository();
        $templateInfo = $templateRepository->getTemplateInfo($this->ciCdOptions->template_group, $this->ciCdOptions->template_key);

        // create variables for template
        $withDisableStages = $templateInfo->allowToggleStages;
        if ($withDisableStages) {
            $variableBag->add(
                new Variable(
                    key: 'CI_COMPOSER_STAGE',
                    scope: '*',
                    value: $this->ciCdOptions->isStageDisabled(CiCdOptions::PrepareStage) ? 0 : 1,
                )
            );
            $variableBag->add(
                new Variable(
                    key: 'CI_BUILD_STAGE',
                    scope: '*',
                    value: $this->ciCdOptions->isStageDisabled(CiCdOptions::BuildStage) ? 0 : 1,
                )
            );
        }

        $gitlabVariablesCreator = new GitlabVariablesCreator($this->gitLabService->gitLabManager());
        $gitlabVariablesCreator
            ->setProject($this->state->getConfigurations()->project)
            ->setVariableBag($variableBag);

        $gitlabVariablesCreator->execute();

        // print error messages
        // todo - store error messages
        if ($fails = $gitlabVariablesCreator->getFailMassages()) {
            $this->logWriter->error('Failed to create variables:');
            foreach ($fails as $failMessage) {
                $this->logWriter->error("GitLab fail message: {$failMessage}");
            }
        }
    }

    private function taskCopySshKeysOnRemoteHost(): void
    {
        $sshOptions = $this->currentStageInfo->options->ssh;
        $useCustomSshKeys = $sshOptions->useCustomSshKey;

        if ($useCustomSshKeys) {
            $this->logWriter->warning('Custom SSH keys enabled. Skip copying keys to remote host');

            return;
        }

        $identityFilePath = $this->state->getReplacements()->get('IDENTITY_FILE_PUB');
        if (!File::exists($identityFilePath)) {
            $this->logWriter->error('Can not find public key file. Skip copying keys to remote host');

            return;
        }

        $publicKey = File::get($identityFilePath);

        $authorizedKeysPath = '.ssh/authorized_keys';

        if ($this->remoteFilesystem->fileExists($authorizedKeysPath)) {
            $existingKeys = $this->remoteFilesystem->get($authorizedKeysPath);
            if (!str_contains($existingKeys, $publicKey)) {
                $this->remoteFilesystem->append($authorizedKeysPath, $publicKey);
            }
        } else {
            $this->remoteFilesystem->put($authorizedKeysPath, $publicKey);
        }
    }

    private function taskGenerateSshKeysOnRemoteHost(): void
    {
        $privateKeyPath = '.ssh/id_rsa';
        $publicKeyPath = "{$privateKeyPath}.pub";

        if (!$this->remoteFilesystem->fileExists($privateKeyPath)) {
            $locallyGeneratedPrivateKeyPath = "{$this->deployFolder}/remote/{$this->currentStageInfo->name}/ssh/id_rsa";
            File::ensureDirectoryExists(File::dirname($locallyGeneratedPrivateKeyPath));

            $this->generateIdentityKey($locallyGeneratedPrivateKeyPath, $this->state->getReplacements()->replace('{{USER}}@{{HOST}}'));

            $this->remoteFilesystem->put($privateKeyPath, File::get($locallyGeneratedPrivateKeyPath));
            $this->remoteFilesystem->put($publicKeyPath, File::get("{$locallyGeneratedPrivateKeyPath}.pub"));
        }

        // update variable
        $publicKeyContent = $this->remoteFilesystem->get($publicKeyPath);

        $this->state->getGitlabVariablesBag()->add(
            new Variable(
                key: 'SSH_PUB_KEY',
                scope: $this->state->getStage()->name,
                value: trim($publicKeyContent) . PHP_EOL ?: '',
            ),
        );
    }

    private function taskAddGitlabToKnownHostsOnRemoteHost(): void
    {
        $gitlabPublicKey = $this->getGitlabPublicKey();

        $knownHostsPath = '.ssh/known_hosts';

        if ($this->remoteFilesystem->fileExists($knownHostsPath)) {
            $existingKeys = $this->remoteFilesystem->get($knownHostsPath);
            if (!str_contains($existingKeys, $gitlabPublicKey)) {
                $this->remoteFilesystem->append($knownHostsPath, $gitlabPublicKey);
            }
        } else {
            $this->remoteFilesystem->put($knownHostsPath, $gitlabPublicKey);
        }
    }

    private function getGitlabPublicKey(): string
    {
        $scanResult = '';
        $gitlabServer = config('gitlab-deploy.gitlab-server');
        $command = ['ssh-keyscan', '-t', 'ssh-rsa', $gitlabServer];
        $status = (new Process($command))->run(function ($type, $buffer) use (&$scanResult) {
            $scanResult = trim($buffer);
        });

        if ($status !== 0 || !Str::containsAll($scanResult, [$gitlabServer, 'ssh-rsa'])) {
            throw new RuntimeException('Failed to retrieve public key for Gitlab server.');
        }

        return $scanResult;
    }

    private function taskPrepareAndCopyDotEnvFileForRemote(): void
    {
        if ($this->isFrontend()) {
            return;
        }

        $envExampleFileContent = $this->gitLabService->getFileContent($this->gitlabProject, '.env.example');

        // /home/user
        $homeDirectory = $this->currentStageInfo->options->homeFolder;
        // /home/user/web/domain.com/public_html
        $baseDir = $this->state->getReplacements()->replace($this->state->getStage()->options->baseDir);

        // web/domain.com/public_html/shared
        $sharedFolder = str($baseDir)
            ->replace($homeDirectory, '')
            ->trim('/')
            ->append('/shared');

        $envFilePath = "{$sharedFolder}/.env";

        if ($this->remoteFilesystem->fileExists($envFilePath)) {
            return;
        }

        $envReplaces = $this->getEnvReplaces();

        $contents = $envExampleFileContent;

        foreach ($envReplaces as $pattern => $replacement) {
            $replacement = $this->state->getReplacements()->replace($replacement);

            // escape $ character
            $replacement = str_replace('$', '\$', $replacement);

            $contents = preg_replace("/{$pattern}/m", $replacement, $contents);
        }

        $this->remoteFilesystem->put($envFilePath, $contents);
    }

    private function getEnvReplaces(): array
    {
        $mail = $this->state->getStage()->hasMailOptions()
            ? [
                '^MAIL_HOST=.*$' => 'MAIL_HOST={{MAIL_HOSTNAME}}',
                '^MAIL_PORT=.*$' => 'MAIL_PORT=587',
                '^MAIL_USERNAME=.*$' => 'MAIL_USERNAME={{MAIL_USER}}',
                '^MAIL_PASSWORD=.*$' => 'MAIL_PASSWORD="{{MAIL_PASSWORD}}"',
                '^MAIL_ENCRYPTION=.*$' => 'MAIL_ENCRYPTION=tls',
                '^MAIL_FROM_ADDRESS=.*$' => 'MAIL_FROM_ADDRESS={{MAIL_USER}}',
            ]
            : [];

        return array_merge($mail, [
            '^APP_KEY=.*$' => 'APP_KEY=' . $this->generateRandomKey(),
            '^APP_URL=.*$' => 'APP_URL={{DEPLOY_DOMAIN}}',

            '^DB_DATABASE=.*$' => 'DB_DATABASE={{DB_DATABASE}}',
            '^DB_USERNAME=.*$' => 'DB_USERNAME={{DB_USERNAME}}',
            '^DB_PASSWORD=.*$' => 'DB_PASSWORD="{{DB_PASSWORD}}"',
        ]);
    }

    /**
     * Generate a random key for the application.
     *
     * @return string
     * @see KeyGenerateCommand::generateRandomKey()
     */
    private function generateRandomKey(): string
    {
        return 'base64:' . base64_encode(Encrypter::generateKey(config('app.cipher')));
    }

    private function taskInsertCustomAliasesOnRemoteHost(): void
    {
        if ($this->isFrontend()) {
            return;
        }

        $options = $this->currentStageInfo->options->bashAliases;
        if (!$options->insert) {
            $this->logWriter->info('Custom aliases not enabled for stage');

            return;
        }

        $this->logWriter->info('Inserting custom aliases on remote host');

        $currentContent = $this->remoteFilesystem->fileExists('.bash_aliases')
            ? $this->remoteFilesystem->get('.bash_aliases')
            : '';

        $content = str($currentContent);
        $variableBag = $this->state->getGitlabVariablesBag();
        $bashAliasesContent = view('deployer.bash_aliases', [
            'artisanCompletion' => $options->artisanCompletion && !$content->contains(['complete -F', '_artisan']),
            'artisanAliases' => $options->artisanAliases && !$content->contains(['alias artisan=', 'alias a=']),
            'composerAlias' => $options->composerAlias && !$content->contains(['alias pcomopser=']),
            'folderAliases' => $options->folderAliases && !$content->contains(['alias cur=']),
            'BIN_COMPOSER' => $variableBag->get('BIN_COMPOSER')?->value,
            'BIN_PHP' => $variableBag->get('BIN_PHP')?->value,
            'DEPLOY_BASE_DIR' => $variableBag->get('DEPLOY_BASE_DIR')?->value,
        ])->render();

        $newContent = trim($currentContent . PHP_EOL . PHP_EOL . trim($bashAliasesContent));

        $this->remoteFilesystem->put('.bash_aliases', $newContent);

        $this->logWriter->info('Custom aliases inserted');
    }

    private function isTestingProject(): bool
    {
        return in_array($this->gitlabProject->id, $this->testingProjects);
    }
}
