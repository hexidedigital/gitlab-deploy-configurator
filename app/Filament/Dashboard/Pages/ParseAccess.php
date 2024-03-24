<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Dashboard\Pages\ParseAccess\ParserTait;
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
    use ParserTait;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.dashboard.pages.parse-access';

    /**
     * Form state
     */
    public array $data = [];

    public array $types = [
        'json',
        'yaml',
        'php',
    ];
    public bool $parsed = false;
    public bool $isReadyToDeploy = false;

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
    }

    protected function getDefaultFormState(): array
    {
        $sampleInput = <<<'DOC'
web.example.nwdev.ent
Domain: https://web.example.nwdev.ent
Host: web.example.nwdev.ent
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

        $this->fill([
            'parsed' => false,
        ]);

        return [
            'access_input' => $sampleInput,
            'gitlab' => [
                'project' => [
                    'selected_id' => '',
                    'name' => '',
                    'token' => config('services.gitlab.token'),
                    'project_id' => '',
                    'domain' => config('services.gitlab.url'),
                ],
            ],
            'stage' => [
                'name' => 'dev',
                'options' => [
                    'git_url' => '',
                    'base_dir_pattern' => '/home/{{USER}}/web/{{HOST}}/public_html',
                    'bin_composer' => '/usr/bin/php8.2 /usr/bin/composer',
                    'bin_php' => '/usr/bin/php8.2',
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

            dump($this->form->getState());

            return;
        }

        dd($this->form->getState());
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
//                        ->disabled(fn () => !($this->parsed && $this->isReadyToDeploy))
                        ->action('setupRepository'),
                )
                ->schema([
                    $this->createGitlabStep(),             // step 1
                    $this->createProjectStep(),            // step 2
                    $this->createServerDetailsStep(),      // step 3
                    $this->createCiCdStep(),               // step 4
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
                Forms\Components\TextInput::make('gitlab.project.token')
                    ->label('API auth token to access to the project')
                    ->helperText(new HtmlString('Read where to get this token <a href="https://github.com/hexidedigital/laravel-gitlab-deploy#gitlab-api-access-token" class="underline" target="_blank">here</a>'))
                    ->required(),
                Forms\Components\TextInput::make('gitlab.project.domain')
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
                $project = $this->findProject($get('gitlab.project.selected_id'));

                $level = data_get($project, 'permissions.project_access.access_level');

                if (!$this->isValidAccessLevel($level)) {
                    Notification::make()->title('You have no access to settings for this project!')->danger()->send();

                    throw new Halt();
                }
            })
            ->schema([
                /* todo - add web url to access (hint or action) */
                Forms\Components\Select::make('gitlab.project.selected_id')
                    ->label('Project')
                    ->placeholder('Select project...')
                    ->required()
                    ->live()
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search) {
                        /*todo - make request to search in gitlab */
                        return collect($this->projects)
                            ->filter(fn (array $project) => str_contains($project['name'], $search))
                            ->map(fn (array $project) => $project['name']);
                    })
                    ->options(function () {
                        return collect($this->projects)
                            ->map(fn (array $project) => $project['name']);
                    })
                    ->afterStateUpdated(function (Forms\Get $get) {
                        $projectID = $get('gitlab.project.selected_id');

                        $this->selectProject($projectID);
                    }),

                Forms\Components\Section::make()
                    ->heading('Project Details')
                    ->schema([
                        Forms\Components\Placeholder::make('placeholder.access_level')
                            ->label('Access level')
                            ->content(function (Forms\Get $get) {
                                $project = $this->findProject($get('gitlab.project.selected_id'));

                                $level = data_get($project, 'permissions.project_access.access_level');

                                return match (intval($level)) {
                                    0 => 'No access',
                                    5 => 'Minimal access',
                                    10 => 'Guest',
                                    20 => 'Reporter',
                                    30 => 'Developer',
                                    40 => 'Maintainer',
                                    50 => 'Owner',
                                    default => '(not-detected) . ' . $level,
                                };
                            }),

                        Forms\Components\Placeholder::make('placeholder.web_url')
                            ->content(fn (Forms\Get $get) => new HtmlString(
                                sprintf(
                                    '<a href="%s" class="underline" target="_blank">%s</a>',
                                    $get('gitlab.project.web_url'),
                                    $get('gitlab.project.web_url')
                                )
                            )),

                        Forms\Components\Placeholder::make('placeholder.name')
                            ->content(fn (Forms\Get $get) => $get('gitlab.project.name')),

                        Forms\Components\Placeholder::make('placeholder.project_id')
                            ->label('Project ID')
                            ->content(fn (Forms\Get $get) => $get('gitlab.project.project_id')),

                        Forms\Components\Placeholder::make('placeholder.git_url')
                            ->label('Git url')
                            ->content(fn (Forms\Get $get) => $get('stage.options.git_url')),
                    ]),

            ]);
    }

    protected function createServerDetailsStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('Server details')
            ->icon('heroicon-o-server')
            ->afterValidation(function ($state) {
                // dd($state);
            })
            ->schema([
                Forms\Components\TextInput::make('stage.options.base_dir_pattern')
                    ->required(),
                Forms\Components\TextInput::make('stage.options.bin_composer')
                    ->required(),
                Forms\Components\TextInput::make('stage.options.bin_php')
                    ->required(),
            ]);
    }

    protected function createCiCdStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('CI/CD details')
            ->icon('heroicon-o-server')
            ->schema([
                Forms\Components\TextInput::make('stage.name')
                    ->label('Stage name (branch name)')
                    ->placeholder('dev/stage/master/prod')
                    ->datalist([
                        'dev',
                        'stage',
                        'master',
                        'prod',
                    ])
                    ->required(),

                /* todo - enabled stages (prepare/vendor, build)*/
            ]);
    }

    protected function createParseAccessStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('Parse access')
            ->icon('heroicon-o-key')
            ->schema([
                Forms\Components\Grid::make(5)->schema([
                    Forms\Components\Section::make('Access')
                        ->columnSpan(2)
                        ->footerActions([
                            Forms\Components\Actions\Action::make('parse')
                                ->label('Parse')
                                ->icon('heroicon-o-bolt')
                                ->color(Color::Green)
                                ->size(ActionSize::Small)
                                ->action(function (Forms\Get $get, Forms\Set $set) {
                                    $accessInput = $get('access_input');
                                    $configurations = $this->form->getRawState();

                                    $parser = $this->tryToParseAccessInput($accessInput);

                                    if (!$parser) {
                                        return;
                                    }

                                    $parser->setConfigurations($configurations);

                                    $parser->buildDeployPrepareConfig();

                                    $set('accessInfo', $parser->getAccessInfo());
                                    $set('contents.deploy_php', $parser->contentForDeployerScript());
                                    $set('contents.deploy_yml', $parser->contentForDeployPrepareConfig());

                                    $notResolved = $parser->getNotResolved();

                                    if (!empty($notResolved)) {
                                        Notification::make()->title('You have unresolved data')->danger()->send();

                                        $set('notResolved', $notResolved);
                                    } else {
                                        $set('notResolved', null);
                                    }

                                    $this->fill([
                                        'parsed' => true,
                                    ]);

                                    Notification::make()->title('Parsed!')->success()->send();
                                }),
                        ])
                        ->footerActionsAlignment(Alignment::End)
                        ->collapsible()
                        ->persistCollapsed(false)
                        ->collapsed(fn () => $this->parsed)
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
                                ->afterStateUpdated(function (?string $state) {
                                    $this->fill(['parsed' => false]);

                                    if (!$state) {
                                        return;
                                    }

                                    $this->tryToParseAccessInput($state);
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
                                    ->label('Section ' . $info['chunk'])
                                    ->content(str(collect($info['lines'])->map(fn ($line) => "- $line")->implode(PHP_EOL))->markdown()->toHtmlString());
                            })->all();
                        }),

                    Forms\Components\Section::make('Parsed result')
                        ->visible(fn () => $this->parsed)
                        ->columnSpanFull()
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

                    Forms\Components\Section::make('Generated files')
                        ->visible(fn () => $this->parsed)
                        ->columnSpanFull()
                        ->columns()
                        ->schema(function () {
                            $files = [
                                [
                                    'state' => 'deploy_php',
                                    'label' => str('`deploy.php`')->markdown()->toHtmlString(),
                                    'download' => str('Download `deploy.php`')->markdown()->toHtmlString(),
                                    'generatePathClosure' => fn (AccessParser $parser) => $parser->makeDeployerPhpFile(),
                                ],
                                [
                                    'state' => 'deploy_yml',
                                    'label' => str('`deploy-prepare.yml`')->markdown()->toHtmlString(),
                                    'download' => str('Download `deploy-prepare.yml`')->markdown()->toHtmlString(),
                                    'generatePathClosure' => fn (AccessParser $parser) => $parser->makeDeployPrepareYmlFile(),
                                ],
                            ];

                            $schema = [];

                            foreach ($files as $fileInfo) {
                                $schema[] = Forms\Components\Grid::make(1)->columnSpan(1)->schema([
                                    Forms\Components\Textarea::make('contents.' . $fileInfo['state'])
                                        ->label($fileInfo['label'])
                                        ->hintAction(
                                            Forms\Components\Actions\Action::make('copyResult')
                                                ->icon('heroicon-m-clipboard')
                                                ->label('Copy content')
                                                ->visible(fn () => $this->parsed)
                                                ->action(function ($state) {
                                                    Notification::make()->title('Copied!')->icon('heroicon-m-clipboard')->send();
                                                    $this->js(
                                                        Blade::render(
                                                            'window.navigator.clipboard.writeText(@js($copyableState))',
                                                            ['copyableState' => $state]
                                                        )
                                                    );
                                                })
                                        )
                                        ->readOnly()
                                        ->extraInputAttributes([
                                            'rows' => 20,
                                        ]),

                                    Forms\Components\Actions::make([
                                        Forms\Components\Actions\Action::make('download.' . $fileInfo['state'])
                                            ->color(Color::Indigo)
                                            ->icon('heroicon-s-arrow-down-tray')
                                            ->label($fileInfo['download'])
                                            ->action(function (Forms\Get $get) use ($fileInfo) {
                                                $accessInput = $get('access_input');
                                                $configurations = $this->form->getRawState();

                                                $parser = $this->tryToParseAccessInput($accessInput);
                                                $parser->setConfigurations($configurations);

                                                $parser->buildDeployPrepareConfig();

                                                $path = call_user_func($fileInfo['generatePathClosure'], $parser);

                                                return response()->download($path)->deleteFileAfterSend();
                                            }),
                                    ]),
                                ]);
                            }

                            return $schema;
                        }),
                ]),
            ]);
    }

    protected function createConfirmationStep(): Forms\Components\Wizard\Step
    {
        return Forms\Components\Wizard\Step::make('Confirmation')
            ->icon('heroicon-o-check-badge')
            ->schema([
                /* todo - check labels, display additional content*/
                Forms\Components\Section::make('Summary')
                    ->schema([
                        Forms\Components\Placeholder::make('placeholder.name')
                            ->label('Repository')
                            ->content(fn (Forms\Get $get) => $get('gitlab.project.name')),

                        Forms\Components\Placeholder::make('placeholder.name')
                            ->label('Stage name (branch)')
                            ->content(fn (Forms\Get $get) => $get('stage.name')),
                    ]),
            ]);
    }

    protected function selectProject(string|int|null $projectID): void
    {
        $project = $this->findProject($projectID);

        if (!$project) {
            return;
        }

        $this->fill([
            'data.gitlab.project.selected_id' => $project['id'],
            'data.gitlab.project.project_id' => $project['id'],
            'data.gitlab.project.name' => $project['name'],
            'data.gitlab.project.web_url' => $project['web_url'],
            'data.stage.options.git_url' => $project['ssh_url_to_repo'],
        ]);

        $level = data_get($project, 'permissions.project_access.access_level');

        if (!$this->isValidAccessLevel($level)) {
            Notification::make()->title('You have no access to settings for this project!')->danger()->send();
        }
    }

    protected function tryToParseAccessInput(string $accessInput): ?AccessParser
    {
        try {
            return $this->parseAccessInput($accessInput);
        } catch (Throwable $e) {
            Notification::make()->title('Invalid content')->body($e->getMessage())->danger()->send();

            return null;
        }
    }
}
