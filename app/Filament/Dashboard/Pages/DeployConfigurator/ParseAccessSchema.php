<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\DeployConfigBuilder;
use App\Filament\Actions\Forms\Components\CopyAction;
use App\Filament\Contacts\HasParserInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator;
use Closure;
use Exception;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconSize;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

class ParseAccessSchema extends Forms\Components\Grid
{
    protected Closure $retrieveConfigurationsUsing;

    protected ?Closure $modifyStageRepeaterUsing = null;
    protected bool|Closure $confirmationCheckboxVisible = true;
    protected bool|Closure $nameInputVisible = false;
    protected bool|Closure $showMoreConfigurationSection = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->columns(1);
        $this->parseConfigurations(fn () => throw new Exception('You must provide a callback to retrieve configurations'));
        $this->schema(function () {
            return [
                $this->getStagesRepeater(),
                $this->getDeployConfigurationSection(),
            ];
        });
    }

    public function parseConfigurations(Closure $callback): static
    {
        return $this->tap(fn () => $this->retrieveConfigurationsUsing = $callback);
    }

    protected function retrieveConfigurations(): array
    {
        return $this->evaluate($this->retrieveConfigurationsUsing);
    }

    public function getStagesRepeater(): Forms\Components\Repeater
    {
        $repeater = Forms\Components\Repeater::make('stages')
            ->hiddenLabel()
            ->itemLabel(fn (array $state) => str($state['name'] ? "Manage **" . $state['name'] . "** stage" : 'Adding new stage...')->markdown()->toHtmlString())
            ->addable(false)
            ->addActionLabel('Add new stage')
            ->deletable(false)
            ->deleteAction(fn (Forms\Components\Actions\Action $action) => $action->requiresConfirmation())
            ->reorderable(false)
            ->collapsible()
            ->schema(function (Forms\Get $get) {
                return [
                    Forms\Components\TextInput::make('name')
                        ->label('Stage name (branch name)')
                        ->hiddenLabel()
                        ->distinct()
                        ->live(onBlur: true)
                        ->placeholder('dev/stage/master/prod')
                        ->datalist([
                            'dev',
                            'stage',
                            'master',
                            'prod',
                        ])
                        ->visible($this->nameInputVisible),

                    Forms\Components\Grid::make(6)
                        ->visible(fn (Forms\Get $get) => $get('name'))
                        ->schema([
                            Forms\Components\Section::make(fn (array $state) => str("Access data for **" . $state['name'] . "** stage")->markdown()->toHtmlString())
                                ->columnSpan(fn (Forms\Get $get) => !is_null($get('notResolved')) ? 4 : 6)
                                ->icon('heroicon-o-key')
                                ->iconColor(fn (Forms\Get $get) => !is_null($get('notResolved')) ? Color::Orange : Color::Blue)
                                ->footerActions([
                                    $this->getParseStageAccessAction(),
                                ])
                                ->footerActionsAlignment(Alignment::End)
                                ->collapsible(fn (Forms\Get $get, HasParserInfo $livewire) => $livewire->getParseStatusForStage($get('name')))
                                ->schema([
                                    Forms\Components\Textarea::make('access_input')
                                        ->label('Access data')
                                        ->hint('paste access data here')
                                        ->autofocus()
                                        ->live(onBlur: true)
                                        ->required()
                                        ->extraInputAttributes([
                                            'rows' => 15,
                                        ])
                                        ->afterStateUpdated(function (?string $state, Forms\Get $get, Forms\Set $set, HasParserInfo $livewire) {
                                            // reset data
                                            $set('can_be_parsed', false);
                                            $set('access_is_correct', false);

                                            $set('server_connection_result', null);
                                            $set('ssh_connected', false);

                                            $livewire->setParseStatusForStage($get('name'), false);

                                            if (!$state) {
                                                return;
                                            }

                                            $this->tryToParseAccessInput($get('name'), $state);
                                        }),
                                ]),

                            Forms\Components\Section::make('Parse problems')
                                ->icon('heroicon-o-exclamation-circle')
                                ->iconColor(Color::Red)
                                ->columnSpan(2)
                                ->visible(fn (Forms\Get $get) => !is_null($get('notResolved')))
                                ->schema(function (Forms\Get $get) {
                                    return collect($get('notResolved'))->map(function ($info) {
                                        return Forms\Components\Placeholder::make('not-resolved-chunk.' . $info['chunk'])
                                            ->label('Section #' . $info['chunk'])
                                            ->content(str(collect($info['lines'])->map(fn ($line) => "- {$line}")->implode(PHP_EOL))->markdown()->toHtmlString());
                                    })->all();
                                }),

                            $this->generateParsedResultSection()
                                ->columnSpanFull()
                                ->collapsed(),

                            $this->generateServerConnectionSection()
                                ->columnSpanFull()
                                ->collapsed(),

                            $this->generateMoreOptionsSections()
                                ->columnSpanFull()
                                ->collapsed(),

                            $this->generateDeployFileDownloadSection()
                                ->columnSpanFull()
                                ->collapsed(),
                        ]),
                ];
            });

        if ($this->modifyStageRepeaterUsing) {
            $repeater = $this->evaluate($this->modifyStageRepeaterUsing, [
                'repeater' => $repeater,
            ]) ?? $repeater;
        }

        return $repeater;
    }

    public function stagesRepeater(?Closure $callback): static
    {
        return $this->tap(fn () => $this->modifyStageRepeaterUsing = $callback);
    }

    public function showNameInput(bool|Closure $callback = true): static
    {
        return $this->tap(fn () => $this->nameInputVisible = $callback);
    }

    public function getDeployConfigurationSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(str('Deploy configuration `.yml` for all stages')->markdown()->toHtmlString())
            ->description('If you want, you can download the configuration file for all stages at once')
            // has at least one parsed stage
            ->visible(function (HasParserInfo $livewire, Forms\Get $get) {
                $defaultState = $livewire->hasParsedStage();

                if (is_null($get('ci_cd_options.template_group'))) {
                    return $defaultState;
                }

                $templateInfo = (new CiCdTemplateRepository())->getTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'));

                return $defaultState && (is_null($templateInfo) || $templateInfo->group->isBackend());
            })
            ->icon('heroicon-o-document-text')
            ->iconColor(Color::Orange)
            ->collapsed()
            ->schema([
                Forms\Components\Textarea::make('contents.deploy_yml')
                    ->label(str('`deploy-prepare.yml`')->markdown()->toHtmlString())
                    ->hintAction(CopyAction::make('deploy_yml'))
                    ->readOnly()
                    ->extraInputAttributes([
                        'rows' => 20,
                    ]),

                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('download.deploy_yml')
                        ->color(Color::Indigo)
                        ->icon('heroicon-s-arrow-down-tray')
                        ->label(str('Download `deploy-prepare.yml`')->markdown()->toHtmlString())
                        ->action(function (DeployConfigBuilder $deployConfigBuilder) {
                            $configurations = $this->retrieveConfigurations();

                            $deployConfigBuilder->parseConfiguration($configurations);

                            $deployConfigBuilder->processStages();

                            $path = $deployConfigBuilder->makeDeployPrepareYmlFile();

                            return response()->download($path)->deleteFileAfterSend();
                        }),
                ])->extraAttributes(['class' => 'items-end']),
            ]);
    }

    public function getParseStageAccessAction(): Closure
    {
        return function (Forms\Get $get) {
            return Forms\Components\Actions\Action::make('parse-' . $get('name'))
                ->label(fn (array $state) => str("Parse **" . $state['name'] . "** access data")->markdown()->toHtmlString())
                ->icon('heroicon-o-bolt')
                ->color(Color::Green)
                ->size(ActionSize::Small)
                ->action(action: function (Forms\Get $get, Forms\Set $set, HasParserInfo $livewire) {
                    // reset data
                    $set('can_be_parsed', false);
                    $set('access_is_correct', false);

                    $set('server_connection_result', null);
                    $set('ssh_connected', false);

                    $configurations = $this->retrieveConfigurations();

                    $stageName = $get('name');
                    $parser = $this->tryToParseAccessInput($stageName, $get('access_input'));
                    if (!$parser) {
                        return;
                    }

                    $parser->parseConfiguration($configurations);

                    $parser->processStages($stageName);

                    $set('can_be_parsed', true);

                    $livewire->setParseStatusForStage($stageName, true);

                    $set('accessInfo', $parser->getAccessInfo($stageName));

                    // get fresh configurations
                    $configurations = $this->retrieveConfigurations();
                    $parser->parseConfiguration($configurations);
                    $set('contents.deploy_php', $parser->contentForDeployerScript($stageName));

                    $set('../../contents.deploy_yml', $parser->contentForDeployPrepareConfig($stageName));

                    $notResolved = $parser->getNotResolved($stageName);

                    if (!empty($notResolved)) {
                        Notification::make()->title('You have unresolved data')->danger()->send();

                        $set('notResolved', $notResolved);
                    } else {
                        $set('notResolved', null);
                    }

                    Notification::make()->title(
                        str("Stage **" . $stageName . "** successfully parsed!")->markdown()->toHtmlString()
                    )->success()->send();
                });
        };
    }

    public function generateMoreOptionsSections(): Forms\Components\Section
    {
        return Forms\Components\Section::make('More options for stage')
            ->description('Additional options for the stage configuration')
            ->visible(fn (Forms\Get $get, HasParserInfo $livewire) => $livewire->getParseStatusForStage($get('name'))
                && $this->sectionBasedOnDataCanBeVisible($get)
                && $this->evaluate($this->showMoreConfigurationSection))
            ->icon('heroicon-o-cog')
            ->iconColor(Color::Blue)
            ->iconSize(IconSize::Large)
            ->schema([
                Forms\Components\Grid::make()->schema([
                    $this->getStatusToggleOptionComponent('options.bash_aliases.insert')
                        ->label('Insert bash aliases')
                        ->helperText('Insert bash aliases to `.bash_aliases` file')
                        ->reactive(),

                    Forms\Components\Fieldset::make('bash_aliases_options')
                        ->label('Bash aliases')
                        ->statePath('options.bash_aliases')
                        ->visible(fn (Forms\Get $get) => $get('options.bash_aliases.insert'))
                        ->columns(1)
                        ->columnSpan(1)
                        ->schema([
                            $this->getStatusToggleOptionComponent('artisanCompletion')->label('Artisan completion'),
                            $this->getStatusToggleOptionComponent('artisanAliases')->label('Artisan aliases'),
                            $this->getStatusToggleOptionComponent('composerAlias')->label('Composer alias'),
                            $this->getStatusToggleOptionComponent('folderAliases')->label('Folder aliases'),
                        ]),
                ]),
            ]);
    }

    public function generateDeployFileDownloadSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(fn (Forms\Get $get) => str("Generated `deploy.php` file for **{$get('name')}** stage")->markdown()->toHtmlString())
            ->description('You can download the generated `deploy.php` file and use it later')
            ->visible(function (Forms\Get $get, HasParserInfo $livewire) {
                $defaultState = $livewire->getParseStatusForStage($get('name')) && $this->sectionBasedOnDataCanBeVisible($get);

                if (is_null($get('../../ci_cd_options.template_group'))) {
                    return $defaultState;
                }

                $templateInfo = (new CiCdTemplateRepository())->getTemplateInfo($get('../../ci_cd_options.template_group'), $get('../../ci_cd_options.template_key'));

                return $defaultState && (is_null($templateInfo) || $templateInfo->group->isBackend());
            })
            ->icon('heroicon-o-document-text')
            ->iconColor(Color::Fuchsia)
            ->collapsed()
            ->schema(function (Forms\Get $get) {
                return [
                    Forms\Components\Grid::make(1)->columnSpan(1)->schema([
                        Forms\Components\Textarea::make('contents.deploy_php')
                            ->label(str('`deploy.php`')->markdown()->toHtmlString())
                            ->hintAction(
                                CopyAction::make('copyResult.' . $get('name'))
                                    ->visible(fn (HasParserInfo $livewire) => $livewire->getParseStatusForStage($get('name')))
                            )
                            ->readOnly()
                            ->disabled()
                            ->extraInputAttributes([
                                'rows' => 20,
                            ]),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('download.deploy_php.' . $get('name'))
                                ->color(Color::Indigo)
                                ->icon('heroicon-s-arrow-down-tray')
                                ->label(str('Download `deploy.php`')->markdown()->toHtmlString())
                                ->action(function (Forms\Get $get) {
                                    $configurations = $this->retrieveConfigurations();

                                    $parser = $this->tryToParseAccessInput($get('name'), $get('access_input'));
                                    if (!$parser) {
                                        return null;
                                    }

                                    $parser->parseConfiguration($configurations);

                                    $parser->processStages();

                                    $path = $parser->makeDeployerPhpFile($get('name'), generateWithVariables: true);

                                    return response()->download($path)->deleteFileAfterSend();
                                }),
                        ])->extraAttributes(['class' => 'items-end']),
                    ]),
                ];
            });
    }

    public function generateParsedResultSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Parse result')
            ->description('Please check the parsed data and confirm it')
            ->visible(fn (Forms\Get $get, HasParserInfo $livewire) => $livewire->getParseStatusForStage($get('name')))
            ->icon('heroicon-o-check-circle')
            ->iconColor(Color::Green)
            ->columns(3)
            ->footerActionsAlignment(Alignment::End)
            ->footerActions([
                Forms\Components\Actions\Action::make('access_is_correct_action')
                    ->label('Accept')
                    ->icon('heroicon-o-check-circle')
                    ->color(Color::Green)
                    ->visible(fn () => $this->isConfirmationCheckboxVisible())
                    ->action(function (Forms\Get $get, Forms\Set $set, HasParserInfo $livewire) {
                        $set('access_is_correct', true);

                        Notification::make()->title('Stage data accepted')->success()->send();
                    }),
            ])
            ->schema(function (HasParserInfo $livewire) {
                return [
                    Forms\Components\Grid::make()->schema([
                        Forms\Components\Grid::make(1)
                            ->columnSpan(1)
                            ->schema([
                                $livewire->getServerFieldset(),
                            ]),

                        Forms\Components\Grid::make(1)
                            ->columnSpan(1)
                            ->schema([
                                $livewire->getMySQLFieldset(),
                                $livewire->getSMTPFieldset(),
                            ]),
                    ]),

                    Forms\Components\Checkbox::make('access_is_correct')
                        ->label('I agree that the access data is correct')
                        ->accepted()
                        ->disabled()
                        ->live()
                        ->visible(fn () => $this->isConfirmationCheckboxVisible())
                        ->columnSpanFull()
                        ->validationMessages([
                            'accepted' => 'Accept this field',
                        ])
                        ->required(),
                ];
            });
    }

    public function generateServerConnectionSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Server connection')
            ->description('Check the SSH connection to the server and retrieve server info')
            ->visible(fn (Forms\Get $get, HasParserInfo $livewire) => $livewire->getParseStatusForStage($get('name')) && $this->sectionBasedOnDataCanBeVisible($get))
            ->icon('heroicon-o-server')
            ->iconColor(Color::Blue)
            ->schema(function () {
                return [
                    Forms\Components\Checkbox::make('options.ssh.use_custom_ssh_key')
                        ->label('Authenticate using custom SSH key')
                        ->required(fn (Forms\Get $get) => empty($get('accessInfo.server.password')))
                        ->reactive(),

                    Forms\Components\Fieldset::make('SSH keys')
                        ->visible(fn (Forms\Get $get) => $get('options.ssh.use_custom_ssh_key'))
                        ->statePath('options.ssh')
                        ->reactive()
                        ->schema([
                            Forms\Components\Textarea::make('private_key')
                                ->placeholder('-----BEGIN OPENSSH PRIVATE KEY-----')
                                ->startsWith('---')
                                ->live()
                                ->required(fn (Forms\Get $get) => empty($get('accessInfo.server.password')))
                                ->helperText('Will be used for authentication and added to GitLab variables under `SSH_PRIVATE_KEY` key'),
                            Forms\Components\TextInput::make('private_key_password')
                                ->disabled()
                                ->helperText('Currently this feature not supported ')
                                ->placeholder('password for key (passphrase)'),
                        ]),

                    Forms\Components\Actions::make([
                        $this->getTestSshButton(),
                        $this->getSshConnectionInfoButton(),
                    ])->columnSpanFull()->alignCenter(),

                    Forms\Components\Fieldset::make('Server details')
                        ->columnSpanFull()
                        ->columns()
                        ->schema(function (Forms\Get $get) {
                            $templateInfo = ($group = $get('../../ci_cd_options.template_group'))
                                ? (new CiCdTemplateRepository())->getTemplateInfo($group, $get('../../ci_cd_options.template_key'))
                                : null;

                            $isBackend = (is_null($templateInfo) || $templateInfo->group->isBackend());

                            return [
                                Forms\Components\Placeholder::make('server_connection_result')
                                    ->label('Server info')
                                    ->columnSpan(1)
                                    ->content(fn (Forms\Get $get) => str($get('server_connection_result') ?: 'not fetched yet')->toHtmlString()),

                                Forms\Components\Grid::make(1)->columnSpan(1)->schema([
                                    Forms\Components\TextInput::make('options.base_dir_pattern')
                                        ->required(),
                                    Forms\Components\TextInput::make('options.home_folder')
                                        ->required(),
                                    Forms\Components\TextInput::make('options.bin_php')
                                        ->visible($isBackend)
                                        ->required($isBackend),
                                    Forms\Components\TextInput::make('options.bin_composer')
                                        ->visible($isBackend)
                                        ->required($isBackend),
                                ]),
                            ];
                        }),
                ];
            });
    }

    public function showConfirmationCheckbox(bool|Closure $callback = true): static
    {
        return $this->tap(fn () => $this->confirmationCheckboxVisible = $callback);
    }

    public function showMoreConfigurationSection(bool|Closure $callback = true): static
    {
        return $this->tap(fn () => $this->showMoreConfigurationSection = $callback);
    }

    protected function connectToServer(array $server, array $sshOptions = []): bool|SSH2
    {
        $ssh = new SSH2($server['host'], $server['port'] ?? 22);

        if (data_get($sshOptions, 'use_custom_ssh_key', false)) {
            $privateKey = data_get($sshOptions, 'private_key');
            if (empty($privateKey)) {
                Notification::make()->title('Private key is empty')->danger()->send();

                return false;
            }

            $key = PublicKeyLoader::load(
                key: $privateKey,
                password: data_get($sshOptions, 'private_key_password') ?: false
            );
        } else {
            $key = data_get($server, 'password');
        }

        if ($ssh->login($server['login'], $key)) {
            return $ssh;
        }

        return false;
    }

    protected function getTestSshButton(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('test_ssh')
            ->label('Check SSH connection')
            ->icon('heroicon-o-key')
            ->disabled(fn (Forms\Get $get) => empty($get('accessInfo.server.password')) && empty($get('options.ssh.private_key')))
            ->action(function (Forms\Get $get, Forms\Set $set) {
                // reset data
                $set('server_connection_result', null);
                $set('ssh_connected', false);

                $server = $get('accessInfo.server');
                $sshOptions = $get('options.ssh');

                $ssh = $this->connectToServer($server, $sshOptions);

                if (!$ssh) {
                    Notification::make()->title('SSH connection failed')->danger()->send();

                    return;
                }

                $set('ssh_connected', true);

                Notification::make()->title('SSH connection successful')->success()->send();
            });
    }

    protected function getSshConnectionInfoButton(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('fetch_server_info')
            ->label('Fetch server info')
            ->disabled(fn (Forms\Get $get) => !$get('ssh_connected'))
            ->color(Color::Green)
            ->icon('heroicon-o-server')
            ->action(function (Forms\Get $get, Forms\Set $set, DeployConfigurator $livewire) {
                $server = $get('accessInfo.server');
                $sshOptions = $get('options.ssh');

                $ssh = $this->connectToServer($server, $sshOptions);

                if (!$ssh) {
                    Notification::make()->title('SSH connection failed')->danger()->send();
                    $set('ssh_connected', false);

                    return;
                }

                $ssh->enableQuietMode();

                $templateInfo = ($group = $get('../../ci_cd_options.template_group'))
                    ? (new CiCdTemplateRepository())->getTemplateInfo($group, $get('../../ci_cd_options.template_key'))
                    : null;

                $isBackend = (is_null($templateInfo) || $templateInfo->group->isBackend());

                $homeFolder = str($ssh->exec('echo $HOME'))->trim()->rtrim('/')->value();

                if ($isBackend) {
                    /** @var Collection $paths */
                    $paths = str($ssh->exec('whereis php php8.2 composer'))
                        ->explode(PHP_EOL)
                        ->mapWithKeys(function ($pathInfo, $line) {
                            $binType = Str::of($pathInfo)->before(':')->trim()->value();
                            $all = Str::of($pathInfo)->after("{$binType}:")->ltrim()->explode(' ');
                            $binPath = $all->first();

                            if (!$binType || !$binPath) {
                                return [$line => null];
                            }

                            if (Str::startsWith($binType, 'php')) {
                                $all = $all->reject(fn ($path) => Str::contains($path, ['-', '.gz', 'man']));
                            }

                            return [
                                $binType => [
                                    'bin' => $binPath,
                                    'all' => $all->map(fn ($path) => "{$path}")->implode('; '),
                                ],
                            ];
                        })
                        ->filter();

                    $phpV = '-';
                    $composerV = '-';

                    $phpInfo = $paths->get('php8.2', $paths->get('php'));
                    if (empty($phpInfo['bin'])) {
                        $phpVOutput = 'PHP not found';
                    } else {
                        $binPhp = $phpInfo['bin'];
                        $phpVOutput = $ssh->exec($binPhp . ' -v');

                        $phpV = preg_match('/PHP (\d+\.\d+\.\d+)/', $phpVOutput, $matches) ? $matches[1] : '-';

                        if ($binComposer = $paths->get('composer')['bin'] ?? null) {
                            $composerVOutput = $ssh->exec("{$binPhp} {$binComposer} -V");

                            $composerV = preg_match('/Composer (?:version )?(\d+\.\d+\.\d+)/', $composerVOutput, $matches) ? $matches[1] : '-';
                        } else {
                            $composerVOutput = 'Composer not found';
                        }
                    }
                }
                $info = collect([
                    "home folder: {$homeFolder}",
                    ...($isBackend ? [
                        "<hr>",
                        "bin paths:",
                        ...$paths->map(fn ($path, $type) => "{$type}: {$path['bin']}"),
                        "<hr>",
                        "all php bins: {$phpInfo['all']}",
                        "<hr>",
                        "php: ({$phpV})",
                        $phpVOutput,
                        "<hr>",
                        "composer: ({$composerV})",
                        $composerVOutput,
                        "<hr>",
                    ] : []),
                ])->implode('<br>');

                $set('server_connection_result', $info);

                $testFolder = data_get($livewire, 'data.projectInfo.is_test') ? '/test' : '';

                // set server details options
                $domain = str($server['domain'])->replace(['https://', 'http://'], '')->value();

                $baseDir = in_array($get('name'), ['dev', 'stage'])
                    ? "{$homeFolder}/web/{$domain}/public_html"
                    : "{$homeFolder}/{$domain}/www";
                $set('options.base_dir_pattern', $baseDir . $testFolder);
                $set('options.home_folder', $homeFolder . $testFolder);

                if ($isBackend) {
                    $set('options.bin_php', $phpInfo['bin']);
                    $set('options.bin_composer', "{$phpInfo['bin']} {$paths->get('composer')['bin']}");
                } else {
                    $set('options.bin_php', null);
                    $set('options.bin_composer', null);
                }

                Notification::make()->title('Server info fetched')->success()->send();
            });
    }

    protected function isConfirmationCheckboxVisible(): bool
    {
        return $this->evaluate($this->confirmationCheckboxVisible);
    }

    protected function sectionBasedOnDataCanBeVisible(Forms\Get $get): bool
    {
        return !$this->isConfirmationCheckboxVisible() || $get('access_is_correct');
    }

    protected function getStatusToggleOptionComponent(string $name): Forms\Components\Toggle
    {
        return Forms\Components\Toggle::make($name)
            ->onColor(Color::Green)
            ->offColor(Color::Red)
            ->onIcon('heroicon-o-check-circle')
            ->offIcon('heroicon-o-x-circle');
    }

    protected function tryToParseAccessInput(string $stageName, ?string $accessInput): ?DeployConfigBuilder
    {
        try {
            return resolve(DeployConfigBuilder::class)->parseInputForAccessPayload($stageName, $accessInput);
        } catch (Throwable $e) {
            Notification::make()->title('Invalid content')->body($e->getMessage())->danger()->send();

            return null;
        }
    }
}
