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
use HexideDigital\GitlabDeploy\Gitlab\Tasks\GitlabVariablesCreator;
use HexideDigital\GitlabDeploy\Gitlab\Variable;
use HexideDigital\GitlabDeploy\Helpers\Builders\ConfigurationBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\Logger;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use phpseclib3\Net\SSH2;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

class ConfigureRepositoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use WithGitlab;

    public int $timeout = 120;
    public int $tries = 10;

    private ProjectData $gitlabProject;

    private User $user;
    private array $testingProjects = [
        689, // moik.o / laravel 11 playground
    ];
    private string $deployFolder;
    private DeployerState $state;
    private Filesystem|FilesystemAdapter $remoteFilesystem;
    private LoggerInterface|Logger $logger;

    public function __construct(
        public int $userId,
        public ProjectDetails $projectDetails,
        public CiCdOptions $ciCdOptions,
        public array $deployConfigurations,
    ) {
    }

    public function handle(): void
    {
        $this->logger = Log::channel('daily');

        $projectId = $this->projectDetails->project_id;

        $this->logger->withContext([
            'project_id' => $projectId,
            'user_id' => $this->userId,
        ]);

        $this->logger->info('Start configuring repository for project');

        $project = $this->findProject($projectId);
        if (is_null($project)) {
            $this->fail(new Exception('Project not found or access denied'));

            return;
        }

        $this->gitlabProject = $project;
        $this->user = User::findOrFail($this->userId);

        $this->cleanUpRepository();

        $this->prepareWorkingDirectoryForProjectSetup();

        $this->logger->info('Prepare general configurations...');

        // prepare general configurations
        $deployConfigBuilder = new DeployConfigBuilder();
        $deployConfigBuilder->setStagesList($this->deployConfigurations['stages']);
        $deployConfigBuilder->setProjectDetails($this->projectDetails);

        $this->state = $this->makeDeployState();

        $this->logger->info('Processing stages...');

        foreach ($this->deployConfigurations['stages'] as $stage) {
            $stageName = $stage['name'];

            $this->logger->withContext(['stage' => $stageName]);

            $this->logger->info("Processing stage: {$stageName}");

            // prepare configurations for stage
            $this->selectStage($stageName);

            $this->initSftpClient($stage);

            // task 1 - generate ssh keys on localhost
            $this->logger->info('Step 1: Generating SSH keys on localhost');
            $this->generateSshKeysOnLocalhost();

            // task 2 - copy ssh keys on remote host
            // get content pub key and put to auth_keys on server
            $this->logger->info('Step 2: Copying SSH keys on remote host');
            $this->copySshKeysOnRemoteHost();

            // task 3 - generate ssh keys on remote host
            // fetch existing keys, or generate new one locally (specify user and login) or on remove (need execute)
            $this->logger->info('Step 3: Generating SSH keys on remote host');
            $this->generateSshKeysOnRemoteHost($stageName);

            // task 4 - create project variables on gitlab
            // create and configure gitlab variables
            $this->logger->info('Step 4: Creating project variables on GitLab');
            $this->createProjectVariables();

            // task 5 - add gitlab to known hosts on remote host
            // append content to known_hosts file
            $this->logger->info('Step 5: Adding GitLab to known hosts on remote host');
            $this->addGitlabToKnownHostsOnRemoteHost();

            // task 6 - prepare and copy dot env file for remote
            // copy file to remote (create folder)
            $this->logger->info('Step 6: Preparing and copying .env file for remote');
            $this->prepareAndCopyDotEnvFileForRemote();

            // task 7 - push branch and trigger pipeline (run first deploy command)
            // create deploy branch with new files in repository
            $this->logger->info('Step 7: Creating commit with config files');
            $this->createCommitWithConfigFiles($stageName, $deployConfigBuilder);

            // task 8 - insert custom aliases on remote host
            // create or append file content
            $this->logger->info('Step 8: Inserting custom aliases on remote host');
            $this->insertCustomAliasesOnRemoteHost();

            $this->logger->info("Stage '{$stageName}' processed");
        }

        $this->sendSuccessNotification();

        /* todo - mock */
        if ($this->isTestingProject()) {
            $this->release(60 * 2);
        }
    }

    public function failed(Exception $exception): void
    {
        $this->logger = Log::channel('daily');

        $this->logger->error('Failed configuring repository', [
            'exception' => $exception->getMessage(),
        ]);

        report($exception); //        $this->fail($exception);

        $this->fail($exception);
    }

    private function sendSuccessNotification(): void
    {
        $this->logger->info("Repository '{$this->gitlabProject->name}' configured successfully");

        Notification::make()
            ->success()
            ->icon('heroicon-o-rocket-launch')
            ->title("Repository '{$this->gitlabProject->name}' configured successfully")
            ->actions([
                Action::make('view')
                    ->label('View in GitLab')
                    ->icon('feathericon-gitlab')
                    ->button()
                    ->url("https://gitlab.hexide-digital.com/{$this->gitlabProject->path_with_namespace}/-/pipelines", shouldOpenInNewTab: true),
            ])
            ->sendToDatabase($this->user);
    }

    private function cleanUpRepository(): void
    {
        $this->logger->debug("Cleaning up repository '{$this->gitlabProject->name}'");

        if (!$this->isTestingProject()) {
            $this->logger->debug("Repository '{$this->gitlabProject->name}' is not in testing projects");

            return;
        }

        // delete variables
        $this->logger->debug("Deleting variables for project '{$this->gitlabProject->name}'");
        collect($this->getGitLabManager()->projects()->variables($this->gitlabProject->id))
            // remove all variables except the ones with environment_scope = '*'
            ->reject(fn (array $variable) => str($variable['environment_scope']) == '*')
            ->each(fn (array $variable) => $this->getGitLabManager()->projects()->removeVariable($this->gitlabProject->id, $variable['key'], [
                'filter' => ['environment_scope' => $variable['environment_scope']],
            ]));

        // delete test branches
        $this->logger->debug("Deleting test branches for project '{$this->gitlabProject->name}'");
        collect($this->getGitLabManager()->repositories()->branches($this->gitlabProject->id))
            ->reject(fn (array $branch) => str($branch['name'])->startsWith(['develop']))
            ->filter(fn (array $branch) => str($branch['name'])->startsWith(['test', 'dev']))
            ->each(fn (array $branch) => $this->getGitLabManager()->repositories()->deleteBranch($this->gitlabProject->id, $branch['name']));

        // delete deploy keys
        $this->logger->debug("Deleting deploy keys for project '{$this->gitlabProject->name}'");
        collect($this->getGitLabManager()->projects()->deployKeys($this->gitlabProject->id))
            ->filter(fn (array $key) => str($key['title'])->startsWith(['web-templatelte']))
            ->each(fn (array $key) => $this->getGitLabManager()->projects()->deleteDeployKey($this->gitlabProject->id, $key['id']));

        $this->logger->debug("Repository '{$this->gitlabProject->name}' cleaned up");
    }

    private function createCommitWithConfigFiles(string $stageName, DeployConfigBuilder $deployConfigBuilder): void
    {
        // https://docs.gitlab.com/ee/api/commits.html#create-a-commit-with-multiple-files-and-actions
        $actions = collect([
            [
                // "action" => "create",
                "file_path" => ".gitlab-ci.yml",
                "content" => view('gitlab-ci-yml', [
                    'templateVersion' => $this->ciCdOptions->template_version,
                    'nodeVersion' => $this->ciCdOptions->node_version,
                    'buildStageEnabled' => $this->ciCdOptions->isStageEnabled('build'),
                ])->render(),
            ],
            [
                // "action" => "create",
                "file_path" => "deploy.php",
                "content" => $deployConfigBuilder->contentForDeployerScript($stageName),
            ],
        ])->map(function (array $action) {
            $generatedContent = $action['content'];

            // check if file exists
            $currentContent = $this->getFileContent($this->gitlabProject, $action['file_path']);

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
        })->filter();

        // if files without changes, just create branch
        if ($actions->isEmpty()) {
            $this->logger->info('No changes in config files');

            $this->logger->info("Creating branch '{$stageName}' for deployment");

            $branch = $this->getGitLabManager()->repositories()
                ->createBranch($this->gitlabProject->id, $stageName, $this->gitlabProject->default_branch);

            $this->logger->debug('branch response', ['branch' => $branch]);

            return;
        }

        // otherwise, create commit with new files
        $commit = $this->getGitLabManager()->repositories()->createCommit($this->gitlabProject->id, [
            "branch" => $stageName,
            "start_branch" => $this->gitlabProject->default_branch,
            "commit_message" => "Configure deployment",
            "author_name" => "DeployHelper",
            "author_email" => "deploy-helper@hexide-digital.com",
            "actions" => $actions->all(),
        ]);

        $this->logger->debug('commit response', ['commit' => $commit]);
    }

    private function getGitLabManager(): GitLabManager
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

    private function makeDeployState(): DeployerState
    {
        // config([
        //    'gitlab-deploy.working-dir' => "{$this->deployFolder}/deployer",
        //    'gitlab-deploy.ssh' => [
        //        'key_name' => 'id_rsa',
        //        'folder' => "{$this->deployFolder}/ssh",
        //    ],
        // ]);

        $state = new DeployerState();
        $builder = app(ConfigurationBuilder::class);
        $configurations = $builder->build($this->deployConfigurations);
        $state->setConfigurations($configurations);

        return $state;
    }

    private function selectStage(string $stageName): void
    {
        $this->state->setStage($this->state->getConfigurations()->stageBag->get($stageName));
        $this->state->setupReplacements();
        $this->state->setupGitlabVariables();

        // rewrite replacements
        $this->state->getReplacements()->merge([
            // rewrite working directory
            'PROJ_DIR' => $this->deployFolder,

            'IDENTITY_FILE' => "{$this->deployFolder}/ssh/id_rsa",
            'IDENTITY_FILE_PUB' => "{$this->deployFolder}/ssh/id_rsa.pub",
        ]);

        // automatically enable CI/CD
        $variable = new Variable(
            key: 'CI_ENABLED',
            scope: $stageName,
            value: '1',
        );
        $this->state->getGitlabVariablesBag()->add($variable);
    }

    private function initSftpClient(array $stage): void
    {
        $this->logger->info('Initializing SFTP client');

        $variables = $this->state->getReplacements();

        $host = $variables->get('DEPLOY_SERVER'); // ip or domain
        $login = $variables->get('DEPLOY_USER');
        $password = $variables->get('DEPLOY_PASS');

        $this->logger->info('Checking connection to remote host');
        $ssh = new SSH2($host, ($port =$variables->get('SSH_PORT') ?: 22));
        if (!$ssh->login($login, $password)) {
            $this->logger->error('Failed to connect with ssh', compact(['login', 'host', 'port']));

            throw new RuntimeException('SSH Login failed');
        }
        $this->logger->info('Connection to remote host established');

        $root = data_get($stage, 'options.home_folder') ?: $this->resolveHomeDirectory();
        $this->logger->debug("Using '$root' as root folder for sftp");

        // https://laravel.com/docs/filesystem#sftp-driver-configuration
        // https://flysystem.thephpleague.com/docs/adapter/sftp-v3
        $this->remoteFilesystem = Storage::build([
            'driver' => 'sftp',
            'host' => $host,

            // Settings for basic authentication...
            'username' => $login,
            'password' => $password,

            // Settings for SSH key based authentication with encryption password...
            // 'privateKey' => '/path/to/private_key',
            // 'passphrase' => env('SFTP_PASSPHRASE'),

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

    private function generateSshKeysOnLocalhost(): void
    {
        File::ensureDirectoryExists("{$this->deployFolder}/local_ssh");

        $identityFilePath = $this->state->getReplacements()->get('IDENTITY_FILE');
        $isIdentityKeyExists = File::exists($identityFilePath) || File::exists("{$identityFilePath}.pub");
        if (!$isIdentityKeyExists) {
            $this->generateIdentityKey($identityFilePath, $this->user->email);
        }

        // update private key variable
        $identityFileContent = File::get($identityFilePath) ?: '';
        $variable = new Variable(
            key: 'SSH_PRIVATE_KEY',
            scope: $this->state->getStage()->name,
            value: rtrim($identityFileContent),
        );

        $this->state->getGitlabVariablesBag()->add($variable);
    }

    private function prepareWorkingDirectoryForProjectSetup(): void
    {
        $this->logger->debug('Preparing working directory for project setup');

        $this->deployFolder = Storage::disk('local')->path('deploy_folder/project_' . $this->gitlabProject->id);

        File::ensureDirectoryExists($this->deployFolder);

        $this->logger->debug('Working directory prepared');
    }

    private function generateIdentityKey(string $identityFilePath, string $comment): void
    {
        $command = ['ssh-keygen', '-t', 'rsa', '-N', '', "-f", $identityFilePath, '-C', $comment];

        $status = (new Process($command, $this->deployFolder))
            ->setTimeout(0)
            ->run(function ($type, $output) {
                $this->logger->debug($output);
            });

        if ($status !== 0) {
            throw new RuntimeException('failed');
        }
    }

    private function createProjectVariables(): void
    {
        $variableBag = $this->state->getGitlabVariablesBag();

        // create variables for template
        if ($this->ciCdOptions->withDisableStages()) {
            $variableBag->add(
                new Variable(
                    key: 'CI_COMPOSER_STAGE',
                    scope: '*',
                    value: $this->ciCdOptions->isStagesDisabled('prepare') ? 0 : 1,
                )
            );
            $variableBag->add(
                new Variable(
                    key: 'CI_BUILD_STAGE',
                    scope: '*',
                    value: $this->ciCdOptions->isStagesDisabled('build') ? 0 : 1,
                )
            );
        }

        $gitlabVariablesCreator = new GitlabVariablesCreator($this->getGitLabManager());
        $gitlabVariablesCreator
            ->setProject($this->state->getConfigurations()->project)
            ->setVariableBag($variableBag);

        $gitlabVariablesCreator->execute();

        // print error messages
        // todo - store error messages
        if ($fails = $gitlabVariablesCreator->getFailMassages()) {
            $this->logger->error('Failed to create variables:');
            foreach ($fails as $failMessage) {
                $this->logger->error("GitLab fail message: {$failMessage}");
            }
        }
    }

    private function copySshKeysOnRemoteHost(): void
    {
        $identityFilePath = $this->state->getReplacements()->get('IDENTITY_FILE_PUB');
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

    private function generateSshKeysOnRemoteHost(string $stageName): void
    {
        $privateKeyPath = '.ssh/id_rsa';
        $publicKeyPath = "{$privateKeyPath}.pub";

        if (!$this->remoteFilesystem->fileExists($privateKeyPath)) {
            $locallyGeneratedPrivateKeyPath = "{$this->deployFolder}/remote/{$stageName}/ssh/id_rsa";
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

    private function addGitlabToKnownHostsOnRemoteHost(): void
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

    private function prepareAndCopyDotEnvFileForRemote(): void
    {
        $envExampleFileContent = $this->getFileContent($this->gitlabProject, '.env.example');

        // /home/user
        $homeDirectory = $this->resolveHomeDirectory();
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

    // todo - task insertCustomAliasesOnRemoteHost
    private function insertCustomAliasesOnRemoteHost(): void
    {
        //
    }

    public function isTestingProject(): bool
    {
        return in_array($this->gitlabProject->id, $this->testingProjects);
    }

    public function resolveHomeDirectory(): string
    {
        $variables = $this->state->getReplacements();

        $login = $variables->get('DEPLOY_USER');

        return "/home/{$login}";
    }
}
