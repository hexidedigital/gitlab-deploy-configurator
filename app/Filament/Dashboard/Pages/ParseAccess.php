<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Dashboard\Pages\ParseAccess\WithGitlab;
use App\Parser\AccessParser;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Exceptions\Halt;
use Gitlab\Exception\RuntimeException;
use GuzzleHttp\Utils;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * @property Form $form
 */
class ParseAccess extends Page implements HasForms, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithGitlab;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.dashboard.pages.parse-access';

    /**
     * Form state
     */
    public array $data = [];

    public array $parsed = [];

    public bool $isReadyToDeploy = true;
    public bool $emptyRepo = false;
    public bool $isLaravelRepository = false;

    public function getMaxContentWidth(): MaxWidth|null
    {
        return null;

        return MaxWidth::Full;
    }

    public function mount(): void
    {
        // fill form with default data
        $this->form->fill($this->getDefaultFormState());

        // todo - temporary try to preload projects
        try {
            $projects = $this->loadProjects();
            $this->fill([
                'projects' => $projects,
            ]);
        } catch (Throwable $throwable) {
            Notification::make()
                ->title('Failed to fetch projects')
                ->body(new HtmlString(sprintf('<p>%s</p><p>%s</p>', $throwable::class, $throwable->getMessage())))
                ->danger()
                ->send();
        }

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
                'selected_id' => '',
                'name' => '',
                'token' => config('services.gitlab.token'),
                'project_id' => '',
                'domain' => config('services.gitlab.url'),
                'git_url' => '',
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
        if (!$this->isReadyToDeploy) {
            Notification::make()->title('Not ready to deploy')->danger()->send();

            return;
        }

        $configurations = $this->form->getRawState();

        $parser = new AccessParser();
        $parser->setConfigurations($configurations);

        dd([
            $this->form->getState(),
            $this->data,
            $parser->buildDeployPrepareConfig(),
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
                        ->requiresConfirmation()
//                        ->disabled(fn () => !$this->isReadyToDeploy)
                        ->action('setupRepository'),
                )
                ->schema([
                    $this->createGitlabStep(),             // step 1
                    $this->createProjectStep(),            // step 2
                    $this->createCiCdStep(),               // step 3
                    $this->createServerDetailsStep(),      // step 4
                    $this->createParseAccessStep(),        // step 5
                    $this->createConfirmationStep(),       // step 6
                ]),
        ])->statePath('data');
    }

    protected function createGitlabStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('Gitlab')
            ->icon('feathericon-gitlab')
            ->afterValidation(function () {
                try {
                    $this->fill([
                        'projects' => $this->loadProjects(),
                    ]);

                    Notification::make()->title('Access granted to GitLab')->success()->send();
                } catch (Throwable $throwable) {
                    Notification::make()
                        ->title('Failed to fetch projects')
                        ->body(new HtmlString(sprintf('<p>%s</p><p>%s</p>', $throwable::class, $throwable->getMessage())))
                        ->danger()
                        ->send();

                    throw new Halt();
                }
            })
            ->schema([
                Forms\Components\TextInput::make('projectInfo.token')
                    ->label('API auth token to access to the project')
                    ->helperText(new HtmlString('Read where to get this token <a href="https://github.com/hexidedigital/laravel-gitlab-deploy#gitlab-api-access-token" class="underline" target="_blank">here</a>'))
                    ->required(),
                Forms\Components\TextInput::make('projectInfo.domain')
                    ->label('Your GitLab domain')
                    ->readOnly()
                    ->required(),
            ]);
    }

    protected function createProjectStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('Project')
            ->icon('heroicon-o-bolt')
            ->afterValidation(function (Forms\Get $get) {
                $project = $this->findProject($get('projectInfo.selected_id'));

                $level = $this->determineProjectAccessLevel($project);
                if (!$this->isValidAccessLevel($level)) {
                    Notification::make()->title('You have no access to settings for this project!')->danger()->send();

                    throw new Halt();
                }

                if (!$this->isLaravelRepository) {
                    Notification::make()->title('This is not a Laravel repository!')->warning()->send();

                    throw new Halt();
                }
            })
            ->schema([
                Forms\Components\Select::make('projectInfo.selected_id')
                    ->label('Project')
                    ->placeholder('Select project...')
                    ->required()
                    ->live()
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search) {
                        /* todo - make request to search in gitlab */
                        return collect($this->projects)
                            ->filter(fn (array $project) => str_contains($project['name'], $search))
                            ->map(fn (array $project) => $project['name']);
                    })
                    ->options(function () {
                        return collect($this->projects)
                            ->map(fn (array $project) => $project['name']);
                    })
                    ->afterStateUpdated(function (Forms\Get $get) {
                        $projectID = $get('projectInfo.selected_id');

                        $this->selectProject($projectID);
                    }),

                // if $this->emptyRepo show option which template to use
                Forms\Components\Section::make()
                    ->visible(fn () => $this->emptyRepo)
                    ->description('This repository is empty. To continue, select template to use.')
                    ->schema([
                        Forms\Components\Select::make('projectInfo.repository_template')
                            ->label('Template')
                            ->live()
                            ->options($this->getRepositoryTemplates())
                            ->disableOptionWhen(fn ($value) => $value !== 'laravel-11')
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if (in_array($state, ['laravel-11', 'hd-based-template'])) {
                                    $set('projectInfo.frontend_builder', 'vite');
                                } elseif (in_array($state, ['islm-template', 'hd-based-template'])) {
                                    $set('projectInfo.frontend_builder', 'webpack');
                                } else {
                                    $set('projectInfo.frontend_builder', null);
                                }
                            })
                            ->required(),
                    ]),

                Forms\Components\Section::make()
                    ->heading('Project Details')
                    ->schema([
                        Forms\Components\Placeholder::make('placeholder.access_level')
                            ->label('Access level')
                            ->content(function (Forms\Get $get) {
                                $project = $this->findProject($get('projectInfo.selected_id'));

                                $level = $this->determineProjectAccessLevel($project);

                                return $this->determineAccessLevelLabel($level);
                            }),

                        Forms\Components\Placeholder::make('placeholder.repository_template_type')
                            ->label('Detected project template')
                            ->content(fn (Forms\Get $get) => $get('projectInfo.repository_template')),

                        Forms\Components\Placeholder::make('placeholder.laravel_version')
                            ->label('Detected laravel')
                            ->visible(fn (Forms\Get $get) => $get('projectInfo.laravel_version'))
                            ->content(fn (Forms\Get $get) => $get('projectInfo.laravel_version')),

                        Forms\Components\Placeholder::make('placeholder.frontend_builder')
                            ->label('Frontend builder')
                            ->visible(fn (Forms\Get $get) => $get('projectInfo.frontend_builder'))
                            ->content(fn (Forms\Get $get) => $get('projectInfo.frontend_builder')),

                        Forms\Components\Placeholder::make('placeholder.web_url')
                            ->content(fn (Forms\Get $get) => new HtmlString(
                                sprintf(
                                    '<a href="%s" class="underline" target="_blank">%s</a>',
                                    $get('projectInfo.web_url'),
                                    $get('projectInfo.web_url')
                                )
                            )),

                        Forms\Components\Placeholder::make('placeholder.name')
                            ->content(fn (Forms\Get $get) => $get('projectInfo.name')),

                        Forms\Components\Placeholder::make('placeholder.project_id')
                            ->label('Project ID')
                            ->content(fn (Forms\Get $get) => $get('projectInfo.project_id')),

                        Forms\Components\Placeholder::make('placeholder.git_url')
                            ->label('Git url')
                            ->content(fn (Forms\Get $get) => $get('projectInfo.git_url')),
                    ]),
            ]);
    }

    protected function createCiCdStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('CI/CD details')
            ->icon('heroicon-o-server')
            ->schema([
                Forms\Components\Select::make('ci_cd_options.template_version')
                    ->label('CI/CD template version')
                    ->live()
                    ->options($this->getCiCdTemplateVersions())
                    ->disableOptionWhen(fn (string $value) => !$this->isCiCdTemplateVersionAvailable($value))
                    ->helperText(
                        new HtmlString('See more details about <a href="https://gitlab.hexide-digital.com/packages/gitlab-templates#template-versions" class="underline" target="_blank">template-versions</a>')
                    )
                    ->required(),

                Forms\Components\Fieldset::make('Enabled CI\CD stages')
                    ->visible(fn (Forms\Get $get) => $get('ci_cd_options.template_version') === '3.0')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Checkbox::make('ci_cd_options.enabled_stages.prepare')
                            ->label('Prepare (composer)')
                            ->helperText('Installs vendor dependencies'),
                        Forms\Components\Checkbox::make('ci_cd_options.enabled_stages.build')
                            ->label('Build')
                            ->helperText('Builds assets'),
                        Forms\Components\Checkbox::make('ci_cd_options.enabled_stages.deploy')
                            ->label('Deploy')
                            ->helperText('Deploys to server')
                            ->disabled(),
                    ]),

                Forms\Components\Repeater::make('stages')
                    ->addActionLabel('Add new stage')
                    ->minItems(1)
                    ->grid(3)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Stage name (branch name)')
                            ->hiddenLabel()
                            ->distinct()
                            ->placeholder('dev/stage/master/prod')
                            ->datalist([
                                'dev',
                                'stage',
                                'master',
                                'prod',
                            ])
                            ->required(),
                    ]),
            ]);
    }

    protected function createServerDetailsStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('Server details')
            ->icon('heroicon-o-server')
            ->schema([
                Forms\Components\Repeater::make('stages')
                    ->hiddenLabel()
                    ->itemLabel(fn (array $state) => "Paths for '" . $state['name'] . "' server")
                    ->grid(3)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->schema([
                        Forms\Components\TextInput::make('options.base_dir_pattern')
                            ->required(),
                        Forms\Components\TextInput::make('options.bin_composer')
                            ->required(),
                        Forms\Components\TextInput::make('options.bin_php')
                            ->required(),
                    ]),
            ]);
    }

    protected function createParseAccessStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('Parse access')
            ->icon('heroicon-o-key')
            ->schema([
                Forms\Components\Repeater::make('stages')
                    ->hiddenLabel()
                    ->itemLabel(fn (array $state) => "Manage '" . $state['name'] . "' stage")
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->collapsible()
                    ->schema(function () {
                        return [
                            Forms\Components\Grid::make(5)->schema([
                                Forms\Components\Section::make('Access')
                                    ->columnSpan(2)
                                    ->footerActions([
                                        function (Forms\Get $get) {
                                            return Forms\Components\Actions\Action::make('parse-' . $get('name'))
                                                ->label('Parse \'' . $get('name') . '\' access data')
                                                ->icon('heroicon-o-bolt')
                                                ->color(Color::Green)
                                                ->size(ActionSize::Small)
                                                ->action(function (Forms\Get $get, Forms\Set $set) {
                                                    $configurations = $this->form->getRawState();

                                                    $parser = $this->tryToParseAccessInput($get('name'), $get('access_input'));
                                                    if (!$parser) {
                                                        return;
                                                    }

                                                    $parser->setConfigurations($configurations);

                                                    $parser->buildDeployPrepareConfig($get('name'));

                                                    $set('can_be_parsed', true);
                                                    $this->fill([
                                                        'parsed.' . $get('name') => true,
                                                    ]);

                                                    $set('accessInfo', $parser->getAccessInfo($get('name')));
                                                    $set('contents.deploy_php', $parser->contentForDeployerScript($get('name')));

                                                    $set('../../contents.deploy_yml', $parser->contentForDeployPrepareConfig($get('name')));

                                                    $notResolved = $parser->getNotResolved($get('name'));

                                                    if (!empty($notResolved)) {
                                                        Notification::make()->title('You have unresolved data')->danger()->send();

                                                        $set('notResolved', $notResolved);
                                                    } else {
                                                        $set('notResolved', null);
                                                    }

                                                    Notification::make()->title('Parsed!')->success()->send();
                                                });
                                        },
                                    ])
                                    ->footerActionsAlignment(Alignment::End)
                                    ->collapsible()
                                    ->persistCollapsed(false)
                                    ->collapsed(fn (Forms\Get $get) => data_get($this, 'parsed.' . $get('name')))
                                    ->schema([
                                        Forms\Components\Textarea::make('access_input')
                                            ->label('Text')
                                            ->hint('paste access data here')
                                            ->autofocus()
                                            ->live(onBlur: true)
                                            ->required()
                                            ->extraInputAttributes([
                                                'rows' => 15,
                                            ])
                                            ->afterStateUpdated(function (?string $state, Forms\Get $get, Forms\Set $set) {
                                                $set('can_be_parsed', false);
                                                $this->fill([
                                                    'parsed.' . $get('name') => false,
                                                ]);

                                                if (!$state) {
                                                    return;
                                                }

                                                $this->tryToParseAccessInput($get('name'), $state);
                                            }),
                                    ]),

                                Forms\Components\Section::make('Problems')
                                    ->icon('heroicon-o-exclamation-circle')
                                    ->iconColor(Color::Red)
                                    ->columnSpan(3)
                                    ->visible(fn (Forms\Get $get) => !is_null($get('notResolved')))
                                    ->schema(function (Forms\Get $get) {
                                        return collect($get('notResolved'))->map(function ($info) {
                                            return Forms\Components\Placeholder::make('not-resolved-chunk.' . $info['chunk'])
                                                ->label('Section #' . $info['chunk'])
                                                ->content(str(collect($info['lines'])->map(fn ($line) => "- {$line}")->implode(PHP_EOL))->markdown()->toHtmlString());
                                        })->all();
                                    }),

                                Forms\Components\Section::make('Parsed result')
                                    ->visible(fn (Forms\Get $get) => data_get($this, 'parsed.' . $get('name')))
                                    ->columnSpanFull()
                                    ->collapsible()
                                    ->columns(3)
                                    ->schema([
                                        Forms\Components\Fieldset::make('Server')
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
                                            ]),

                                        Forms\Components\Fieldset::make('MySQL')
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
                                            ]),

                                        Forms\Components\Fieldset::make('SMTP')
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
                                            ]),
                                    ]),

                                Forms\Components\Section::make(str('Generated **deploy** file (for current stage)')->markdown()->toHtmlString())
                                    ->visible(fn (Forms\Get $get) => data_get($this, 'parsed.' . $get('name')))
                                    ->collapsed()
                                    ->schema(function (Forms\Get $get) {
                                        return [
                                            Forms\Components\Grid::make(1)->columnSpan(1)->schema([
                                                Forms\Components\Textarea::make('contents.deploy_php')
                                                    ->label(str('`deploy.php`')->markdown()->toHtmlString())
                                                    ->hintAction(
                                                        $this->createCopyAction($get('name'))
                                                            ->visible(fn () => data_get($this, 'parsed.' . $get('name')))
                                                    )
                                                    ->readOnly()
                                                    ->extraInputAttributes([
                                                        'rows' => 20,
                                                    ]),

                                                Forms\Components\Actions::make([
                                                    Forms\Components\Actions\Action::make('download.deploy_php.' . $get('name'))
                                                        ->color(Color::Indigo)
                                                        ->icon('heroicon-s-arrow-down-tray')
                                                        ->label(str('Download `deploy.php`')->markdown()->toHtmlString())
                                                        ->action(function (Forms\Get $get) {
                                                            $configurations = $this->form->getRawState();

                                                            $parser = $this->tryToParseAccessInput($get('name'), $get('access_input'));
                                                            if (!$parser) {
                                                                return null;
                                                            }

                                                            $parser->setConfigurations($configurations);

                                                            $parser->buildDeployPrepareConfig();

                                                            $path = $parser->makeDeployerPhpFile($get('name'));

                                                            return response()->download($path)->deleteFileAfterSend();
                                                        }),
                                                ])->extraAttributes(['class' => 'items-end']),
                                            ]),
                                        ];
                                    }),
                            ]),
                        ];
                    }),

                Forms\Components\Section::make('Deploy configuration (for all stages)')
                    // has at least one parsed stage
                    ->visible(fn () => collect($this->parsed)->filter()->isNotEmpty())
                    ->schema([
                        Forms\Components\Grid::make(1)->columnSpan(1)->schema([
                            Forms\Components\Textarea::make('contents.deploy_yml')
                                ->label(str('`deploy-prepare.yml`')->markdown()->toHtmlString())
                                ->hintAction($this->createCopyAction('deploy_yml'))
                                ->readOnly()
                                ->extraInputAttributes([
                                    'rows' => 20,
                                ]),

                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('download.deploy_yml')
                                    ->color(Color::Indigo)
                                    ->icon('heroicon-s-arrow-down-tray')
                                    ->label(str('Download `deploy-prepare.yml`')->markdown()->toHtmlString())
                                    ->action(function () {
                                        $configurations = $this->form->getRawState();

                                        $parser = new AccessParser();
                                        $parser->setConfigurations($configurations);

                                        $parser->buildDeployPrepareConfig();

                                        $path = $parser->makeDeployPrepareYmlFile();

                                        return response()->download($path)->deleteFileAfterSend();
                                    }),
                            ])->extraAttributes(['class' => 'items-end']),
                        ]),
                    ]),
            ]);
    }

    protected function createConfirmationStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('Confirmation')
            ->icon('heroicon-o-check-badge')
            ->schema([
                Forms\Components\Section::make('Summary')
                    ->schema([
                        Forms\Components\Placeholder::make('placeholder.name')
                            ->label('Repository')
                            ->content(fn (Forms\Get $get) => $get('projectInfo.name')),

                        // print all stages
                        Forms\Components\Repeater::make('stages')
                            ->itemLabel(fn (array $state) => $state['name'])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                Forms\Components\TextInput::make('name')->readOnly(),
                            ]),
                    ]),

                Forms\Components\Checkbox::make('isReadyToDeploy')
                    ->label('I confirm that I have checked all the data and I am ready to deploy')
                    ->accepted()
                    ->required(),
            ]);
    }

    protected function selectProject(string|int|null $projectID): void
    {
        // reset values
        $this->reset([
            'isLaravelRepository',
            'emptyRepo',
            'parsed',
            'data.projectInfo.laravel_version',
            'data.projectInfo.repository_template',
            'data.projectInfo.frontend_builder',
            'data.projectInfo.selected_id',
            'data.projectInfo.project_id',
            'data.projectInfo.name',
            'data.projectInfo.web_url',
            'data.projectInfo.git_url',
        ]);

        $project = $this->findProject($projectID);

        if (!$project) {
            return;
        }

        $this->fill([
            'data.projectInfo.selected_id' => $project['id'],
            'data.projectInfo.project_id' => $project['id'],
            'data.projectInfo.name' => $project['name'],
            'data.projectInfo.web_url' => $project['web_url'],
            'data.projectInfo.git_url' => $project['ssh_url_to_repo'],
        ]);

        $level = $this->determineProjectAccessLevel($project);
        if (!$this->isValidAccessLevel($level)) {
            Notification::make()->title('You have no access to settings for this project!')->danger()->send();
        }

        $isEmptyRepo = data_get($project, 'empty_repo');
        $this->emptyRepo = $isEmptyRepo;

        if ($isEmptyRepo) {
            Notification::make()->title('This project is empty!')->warning()->send();
        }

        $template = $this->detectProjectTemplate($project);

        $this->fill([
            'data.projectInfo.repository_template' => $template,
        ]);
    }

    protected function detectProjectTemplate(array $project): string
    {
        $isEmptyRepo = data_get($project, 'empty_repo');

        if ($isEmptyRepo) {
            $this->isLaravelRepository = true;

            return 'none (empty repo)';
        }

        $this->makeGitlabManager();

        $template = 'not resolved';

        // fetch project files

        try {
            $file = $this->gitLabManager->repositoryFiles()->getFile($project['id'], 'composer.json', $project['default_branch']);
            $composerJson = Utils::jsonDecode(base64_decode($file['content']), true);

            $laravelVersion = data_get($composerJson, 'require.laravel/framework');
            $usesYajra = data_get($composerJson, 'require.yajra/laravel-datatables-html');

            $this->fill([
                'data.projectInfo.laravel_version' => $laravelVersion,
            ]);

            $between = function ($v, $left, $right) {
                $v = str_replace('^', '', $v);

                return version_compare($v, $left, '>=')
                    && version_compare($v, $right, '<');
            };

            if ($usesYajra) {
                $template = $between($laravelVersion, 9, 10)
                    ? 'islm-template'
                    : 'old-template for laravel ' . $laravelVersion;
            } else {
                $template = $between($laravelVersion, 10, 11)
                    ? 'hd-based-template'
                    : 'laravel-11';
            }

            $this->isLaravelRepository = true;
        } catch (RuntimeException $e) {
            if ($e->getCode() == 404) {
                $this->isLaravelRepository = false;
            } else {
                Notification::make()->title('Failed to detect project template')->danger()->send();
            }
        }

        try {
            $file = $this->gitLabManager->repositoryFiles()->getFile($project['id'], 'package.json', $project['default_branch']);
            $packageJson = Utils::jsonDecode(base64_decode($file['content']), true);

            // vite or webpack or not resolved
            $frontendBuilder = data_get($packageJson, 'devDependencies.vite')
                ? 'vite'
                : (data_get($packageJson, 'devDependencies.webpack') ? 'webpack' : null);

            $this->fill([
                'data.projectInfo.frontend_builder' => $frontendBuilder,
            ]);
        } catch (RuntimeException $e) {
            if ($e->getCode() != 404) {
                Notification::make()->title('Failed to detect project template')->danger()->send();
            }
        }

        return $template;
    }

    protected function tryToParseAccessInput(string $stageName, string $accessInput): ?AccessParser
    {
        try {
            return (new AccessParser())->parseInputForAccessPayload($stageName, $accessInput);
        } catch (Throwable $e) {
            Notification::make()->title('Invalid content')->body($e->getMessage())->danger()->send();

            return null;
        }
    }

    protected function createCopyAction(string $name): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('copyResult.' . $name)
            ->icon('heroicon-m-clipboard')
            ->label('Copy content')
            ->action(function ($state) {
                Notification::make()->title('Copied!')->icon('heroicon-m-clipboard')->send();
                $this->js(
                    Blade::render(
                        'window.navigator.clipboard.writeText(@js($copyableState))',
                        ['copyableState' => $state]
                    )
                );
            });
    }

    protected function getRepositoryTemplates(): array
    {
        return [
            'laravel-11' => 'Laravel 11',
            'islm-template' => 'islm based template (laravel 9)',
            'hd-based-template' => 'HD-based v3 (laravel 8)',
        ];
    }

    protected function getCiCdTemplateVersions(): array
    {
        return [
            '2.0' => '2.0 - Webpack',
            '2.1' => '2.1 - Vite',
            '2.2' => '2.2 - Vite + Composer stage',
            '3.0' => '3.0 - configurable',
        ];
    }

    protected function isCiCdTemplateVersionAvailable(string $value): bool
    {
        return $value === '3.0';
    }

    protected function determineProjectAccessLevel(?array $project): string|int|null
    {
        return data_get(
            $project,
            'permissions.group_access.access_level',
            data_get($project, 'permissions.project_access.access_level')
        );
    }
}
