<?php

namespace App\Filament\Dashboard\Pages;

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
use Gitlab;
use GrahamCampbell\GitLab\GitLabManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.dashboard.pages.parse-access';

    public array $data = [];

    public array $types = [
        'json',
        'yaml',
        'php',
    ];
    public bool $parsed = false;
    public bool $isReadyToDeploy = false;

    public array $projects = [];

    public function getMaxContentWidth(): MaxWidth|null
    {
        return null;

        return MaxWidth::Full;
    }

    public function mount(): void
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
        $parser = $this->parseAccessInput($sampleInput);

        $state = [
            'input' => $sampleInput,
            'result' => [
                'json' => $parser->makeJson(),
                'yaml' => $parser->makeYaml(),
            ],
            'gitlab' => [
                'project' => [
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

        $this->fill([
            'parsed' => true,
            'projects' => [],
        ]);

        $this->form->fill($state);
    }

    public function parse(): void
    {
        $state = $this->form->getState();

        $input = $state['input'];

        $parser = $this->parseAccessInput($input);

        $state['result'] = [
            'json' => $parser->makeJson(),
            'yaml' => $parser->makeYaml(),
        ];

        $this->form->fill($state);

        $this->fill(['parsed' => true]);

        Notification::make()
            ->title('Parsed!')
            ->success()
            ->send();
    }

    public function setupRepository(): void
    {
        if (!$this->isReadyToDeploy) {
            Notification::make()
                ->title('Not ready to deploy')
                ->danger()
                ->send();

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
                    // Gitlab
                    Forms\Components\Wizard\Step::make('Gitlab')
                        ->icon('feathericon-gitlab')
                        ->afterValidation(function (Forms\Get $get, GitLabManager $gitLabManager) {
                            try {
                                $this->authenticateGitlabManager($get, $gitLabManager);

                                $projects = $this->fetchProjects($gitLabManager, [
                                    'order_by' => 'created_at',
                                ]);

                                $this->fill(['projects' => $projects]);

                                Notification::make()
                                    ->title('Access granted to GitLab')
                                    ->success()
                                    ->send();
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
                        ]),

                    // Project
                    Forms\Components\Wizard\Step::make('Project')
                        ->icon('heroicon-o-bolt')
                        ->afterValidation(function (Forms\Get $get) {
                            $project = $this->findProject($get('gitlab.project.selected_id'));

                            $level = data_get($project, 'permissions.project_access.access_level');

                            if (!$this->isValidAccessLevel($level)) {
                                Notification::make()
                                    ->title('You have no access to settings for this project!')
                                    ->danger()
                                    ->send();

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
                                ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                    $project = $this->findProject($get('gitlab.project.selected_id'));

                                    if (!$project) {
                                        return;
                                    }

                                    $level = data_get($project, 'permissions.project_access.access_level');

                                    if (!$this->isValidAccessLevel($level)) {
                                        Notification::make()
                                            ->title('You have no access to settings for this project!')
                                            ->danger()
                                            ->send();
                                    }

                                    $set('gitlab.project.project_id', $project['id']);
                                    $set('gitlab.project.name', $project['name']);
                                    $set('gitlab.project.web_url', $project['web_url']);
                                    $set('stage.options.git_url', $project['ssh_url_to_repo']);
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

                        ]),

                    // Server details
                    Forms\Components\Wizard\Step::make('Server details')
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
                        ]),

                    // CI/CD details
                    Forms\Components\Wizard\Step::make('CI/CD details')
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
                        ]),

                    // Parse access
                    Forms\Components\Wizard\Step::make('Parse access')
                        ->icon('heroicon-o-key')
                        ->schema([
                            Forms\Components\Grid::make(4)->schema([
                                Forms\Components\Section::make('Access')
                                    ->columnSpan(1)
                                    ->footerActions([
                                        Forms\Components\Actions\Action::make('parse')
                                            ->label('Parse')
                                            ->disabled(fn () => $this->parsed)
                                            ->size(ActionSize::Small)
                                            ->action('parse'),
                                    ])
                                    ->footerActionsAlignment(Alignment::End)
                                    ->schema([
                                        Forms\Components\Textarea::make('input')
                                            ->label('Input')
                                            ->autofocus()
                                            ->live(onBlur: true)
                                            ->required()
                                            ->extraInputAttributes([
                                                'rows' => 15,
                                            ])
                                            ->afterStateUpdated(function (?string $state) {
                                                try {
                                                    $this->parsed = false;

                                                    if (!$state) {
                                                        return;
                                                    }

                                                    $this->parseAccessInput($state);

                                                    Notification::make()
                                                        ->title('Content is valid')
                                                        ->info()
                                                        ->send();
                                                } catch (Throwable $e) {
                                                    Notification::make()
                                                        ->title('Invalid content')
                                                        ->body($e->getMessage())
                                                        ->danger()
                                                        ->send();
                                                }
                                            }),
                                    ]),

                                Forms\Components\Section::make('Result')
                                    ->columnSpan(3)
                                    ->schema(function () {
                                        $columns = [];

                                        $types = [
                                            'json',
                                            'yaml',
                                        ];

                                        foreach ($types as $type) {
                                            $columns[] = Forms\Components\Textarea::make('result.' . $type)
                                                ->columnSpan(1)
                                                ->hiddenLabel()
                                                ->hintAction(
                                                    Forms\Components\Actions\Action::make('copyResult')
                                                        ->icon('heroicon-m-clipboard')
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
                                                ]);
                                        }

                                        return [
                                            Forms\Components\Grid::make(1)->schema([
                                                Forms\Components\ToggleButtons::make('download_type')
                                                    ->label('Which format to download')
                                                    ->inline()
                                                    ->live()
                                                    ->grouped()
                                                    ->colors(array_combine($this->types, ['info', 'info', 'info',]))
                                                    ->options(array_combine($this->types, $this->types)),

                                                Forms\Components\Actions::make([
                                                    Forms\Components\Actions\Action::make('Download')
                                                        ->disabled(fn (Forms\Get $get) => !$get('download_type'))
                                                        ->color(Color::Indigo)
                                                        ->label('Download')
                                                        ->action(function (Forms\Get $get) {
                                                            $type = $get('download_type');

                                                            if (!$type) {
                                                                return null;
                                                            }

                                                            $input = $get('input');

                                                            $parser = $this->parseAccessInput($input);

                                                            $file = $parser->storeAsFile($type);

                                                            return response()->download($file)->deleteFileAfterSend();
                                                        }),
                                                ]),
                                            ]),

                                            Forms\Components\Grid::make(count($types))
                                                ->schema($columns),
                                        ];
                                    }),
                            ]),
                        ]),

                    // Confirmation
                    Forms\Components\Wizard\Step::make('Confirmation')
                        ->icon('heroicon-o-check-badge')
                        ->schema([
                            /* todo - check labels, display additional content*/
                            Forms\Components\Section::make('Summary')
                                ->schema([
                                    Forms\Components\Placeholder::make('placeholder.name')
                                        ->content(fn (Forms\Get $get) => $get('gitlab.project.name')),

                                    Forms\Components\Placeholder::make('placeholder.git_url')
                                        ->content(fn (Forms\Get $get) => $get('stage.options.git_url')),

                                    Forms\Components\Placeholder::make('placeholder.base_dir_pattern')
                                        ->content(fn (Forms\Get $get) => $get('stage.options.base_dir_pattern')),

                                    Forms\Components\Placeholder::make('placeholder.bin_composer')
                                        ->content(fn (Forms\Get $get) => $get('stage.options.bin_composer')),

                                    Forms\Components\Placeholder::make('placeholder.bin_php')
                                        ->content(fn (Forms\Get $get) => $get('stage.options.bin_php')),

                                    Forms\Components\Placeholder::make('placeholder.name')
                                        ->content(fn (Forms\Get $get) => $get('stage.name')),
                                ]),
                        ]),
                ]),
        ])->statePath('data');
    }

    protected function authenticateGitlabManager(Forms\Get $get, GitLabManager $gitLabManager): void
    {
        $token = $get('gitlab.project.token');
        $url = $get('gitlab.project.domain');

        $gitLabManager->setUrl($url);
        $gitLabManager->authenticate($token, Gitlab\Client::AUTH_HTTP_TOKEN);
    }

    protected function fetchProjects(GitLabManager $gitLabManager, array $filters): array
    {
        $projects = $gitLabManager->projects()->all([
            'page' => 1,
            'per_page' => 30,
            'min_access_level' => 30, // developer
            ...$filters,
        ]);

        return (new Collection($projects))
            ->mapWithKeys(fn (array $project) => [
                $project['id'] => Arr::only($project, [
                    'id',
                    'name',
                    'name_with_namespace',
                    'ssh_url_to_repo',
                    'default_branch',
                    'web_url',
                    'avatar_url',
                    'empty_repo',
                    'permissions',
                ]),
            ])
            ->toArray();
    }

    /*todo - find project in array and when missing - in api*/
    protected function findProject(string|int|null $id): ?array
    {
        return data_get($this->projects, $id);
    }

    protected function parseAccessInput(?string $input): AccessParser
    {
        $parser = new AccessParser();
        $parser->setInput($input);
        $parser->parse();

        return $parser;
    }

    // https://docs.gitlab.com/ee/api/access_requests.html#valid-access-levels
    protected function isValidAccessLevel($level): bool
    {
        return $level === 40 || $level === 50;
    }
}
