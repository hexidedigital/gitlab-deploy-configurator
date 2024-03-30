<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Dashboard\Pages\DeployConfigurator\WithGitlab;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithProjectInfoManage;
use App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;
use App\Parser\DeployConfigBuilder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;

/**
 * @property Form $form
 */
class DeployConfigurator extends Page implements HasForms, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithGitlab;
    use WithProjectInfoManage;

    protected static ?string $navigationIcon = 'heroicon-o-cog';

    protected static string $view = 'filament.pages.base-edit-page';

    /**
     * Form state
     */
    public array $data = [];

    /**
     * @var array<string, bool>
     */
    public array $parsed = [];

    public bool $emptyRepo = false;
    public bool $isLaravelRepository = false;

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return null;
    }

    public function mount(): void
    {
        // fill form with default data
        $this->form->fill($this->getDefaultFormState());

        // todo - select laravel 11 playground
        $this->selectProject('689');
        // todo - select deploy parser (empty project)
        //        $this->selectProject('700');
    }

    protected function getDefaultFormState(): array
    {
        $sampleInput = <<<'DOC'
            web.example.nwdev.net
            Domain: https://web.example.nwdev.net
            Host: web.example.nwdev.net
            Login: web-example-dev
            Password: XxXxXxXXXXX

            MySQL:
            web-example-dev_db
            web-example-dev_db
            XXxxxxxXXXXXXxx

            SMTP:
            Hostname: devs.hexide-digital.com
            Username: example@nwdev.net
            Password: XxXxXxXXXXX
            DOC;

        $defaultStageOptions = [
            'base_dir_pattern' => '/home/{{USER}}/web/{{HOST}}/public_html',
            'bin_composer' => '/usr/bin/php8.2 /usr/bin/composer',
            'bin_php' => '/usr/bin/php8.2',
        ];

        return [
            'access_input' => $sampleInput,
            'projectInfo' => [
                'token' => config('services.gitlab.token'),
                'domain' => config('services.gitlab.url'),

                'selected_id' => null,
                'name' => null,
                'project_id' => null,
                'git_url' => null,
            ],
            'ci_cd_options' => [
                'template_version' => '3.0',
                'enabled_stages' => [
                    'prepare' => true,
                    'build' => true,
                    'deploy' => true,
                ],
            ],
            'stages' => [
                [
                    'name' => 'dev',
                    'access_input' => $sampleInput,
                    'options' => [
                        ...$defaultStageOptions,
                    ],
                ],
                [
                    'name' => 'stage',
                    'access_input' => str($sampleInput)->replace([
                        'nwdev.net',
                        'dev',
                    ], [
                        'hdit.info',
                        'stage',
                    ])->toString(),
                    'options' => [
                        ...$defaultStageOptions,
                    ],
                ],
            ],
        ];
    }

    /**
     * Action for last wizard step and form
     */
    public function setupRepository(): void
    {
        $configurations = $this->form->getRawState();

        $deployConfigBuilder = new DeployConfigBuilder();
        $deployConfigBuilder->setConfigurations($configurations);

        dd([
            $this->form->getState(),
            $this->data,
            $deployConfigBuilder->buildDeployPrepareConfig(),
        ]);

        $this->createCommitWithConfigFiles();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Wizard::make()
                ->columnSpanFull()
                ->submitAction(
                    Forms\Components\Actions\Action::make('prepare repository')
                        ->label('Prepare repository')
                        ->icon('heroicon-o-rocket-launch')
                        ->color(Color::Green)
                        ->action('setupRepository'),
                )
                ->nextAction(function (Forms\Components\Actions\Action $action) {
                    $action
                        ->icon('heroicon-o-chevron-double-right');
                })
                ->schema([
                    Wizard\GitlabStep::make(),              // step 1
                    Wizard\ProjectStep::make(),             // step 2
                    Wizard\CiCdStep::make(),                // step 3
                    Wizard\ServerDetailsStep::make(),       // step 4
                    Wizard\ParseAccessStep::make(),         // step 5
                    Wizard\ConfirmationStep::make(),        // step 6
                ]),
        ])->statePath('data');
    }

    public function getServerFieldset(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('Server')
            ->columns(1)
            ->columnSpan(1)
            ->schema([
                Forms\Components\Placeholder::make('accessInfo.server.domain')
                    ->label('Domain')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.domain')),
                Forms\Components\Placeholder::make('accessInfo.server.host')
                    ->label('Host')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.host')),
                Forms\Components\Placeholder::make('accessInfo.server.port')
                    ->label('Port')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.port') ?: 22),
                Forms\Components\Placeholder::make('accessInfo.server.login')
                    ->label('Login')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.login')),
                Forms\Components\Placeholder::make('accessInfo.server.password')
                    ->label('Password')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.password')),
            ]);
    }

    public function getMySQLFieldset(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('MySQL')
            ->columns(1)
            ->columnSpan(1)
            ->visible(fn (Forms\Get $get) => !is_null($get('accessInfo.database')))
            ->schema([
                Forms\Components\Placeholder::make('accessInfo.database.database')
                    ->label('Database')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.database.database')),
                Forms\Components\Placeholder::make('accessInfo.database.username')
                    ->label('Username')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.database.username')),
                Forms\Components\Placeholder::make('accessInfo.database.password')
                    ->label('Password')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.database.password')),
            ]);
    }

    public function getSMTPFieldset(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('SMTP')
            ->columns(1)
            ->columnSpan(1)
            ->visible(fn (Forms\Get $get) => !is_null($get('accessInfo.mail')))
            ->schema([
                Forms\Components\Placeholder::make('accessInfo.mail.hostname')
                    ->label('Hostname')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.mail.hostname')),
                Forms\Components\Placeholder::make('accessInfo.mail.username')
                    ->label('Username')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.mail.username')),
                Forms\Components\Placeholder::make('accessInfo.mail.password')
                    ->label('Password')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.mail.password')),
            ]);
    }

    protected function createCommitWithConfigFiles(): void
    {
        $project_id = 689;
        $stageName = 'test/dev/' . now()->format('His');

        $project = $this->findProject($project_id);

        $branches = collect($this->getGitLabManager()->repositories()->branches($project_id))
            ->keyBy('name');

        if (empty($branches)) {
            return;
        }

        return;
        // todo
        $branches
            ->filter(fn (array $branch) => str($branch)->startsWith('test'))
            ->each(function (array $branch) use ($project_id) {
                $this->getGitLabManager()->repositories()->deleteBranch($project_id, $branch['name']);
            });

        $defaultBranch = $project['default_branch'];
        if (!$branches->has($stageName)) {
            //            $newBranch = $this->getGitLabManager()->repositories()->createBranch($project_id, $stageName, $defaultBranch);
        }

        // https://docs.gitlab.com/ee/api/commits.html#create-a-commit-with-multiple-files-and-actions
        $this->getGitLabManager()->repositories()->createCommit($project_id, [
            "branch" => $stageName,
            "start_branch" => $defaultBranch,
            "commit_message" => "Configure deployment " . now()->format('H:i:s'),
            "author_name" => "DeployHelper",
            "author_email" => "deploy-helper@hexide-digital.com",
            "actions" => [
                [
                    "action" => "create",
                    "file_path" => ".deploy/config-encoded.yml",
                    "content" => base64_encode("test payload in base64"),
                    "encoding" => "base64",
                ],
                [
                    "action" => "create",
                    "file_path" => ".deploy/config-raw.yml",
                    "content" => "test payload in raw",
                ],
            ],
        ]);
    }
}
