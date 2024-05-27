<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\Data\Stage\SshOptions;
use App\Domains\DeployConfigurator\DeployConfigBuilder;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Server;
use App\Domains\DeployConfigurator\ServerDetailParser;
use App\Filament\Actions\Forms\Components\CopyAction;
use App\Filament\Contacts\HasParserInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator;
use Closure;
use DomainException;
use Exception;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconSize;
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

    public function stagesRepeater(?Closure $callback): static
    {
        return $this->tap(fn () => $this->modifyStageRepeaterUsing = $callback);
    }

    public function showNameInput(bool|Closure $callback = true): static
    {
        return $this->tap(fn () => $this->nameInputVisible = $callback);
    }

    protected function getStagesRepeater(): Forms\Components\Repeater
    {
        $repeater = Forms\Components\Repeater::make('stages')
            ->hiddenLabel()
            ->itemLabel(fn (array $state) => str($state['name'] ? "Manage stage: **{$state['name']}**" : 'Adding new stage...')->markdown()->toHtmlString())
            ->addable(false)
            ->addActionLabel('Add new stage')
            ->deletable(false)
            ->deleteAction(fn (Forms\Components\Actions\Action $action) => $action->requiresConfirmation())
            ->reorderable(false)
            ->collapsible()
            ->schema(function (Forms\Get $get) {
                return [
                    Forms\Components\TextInput::make('name')
                        ->label('Branch name')
                        ->hiddenLabel()
                        ->distinct()
                        ->live(onBlur: true)
                        ->placeholder('dev/stage/master/prod')
                        ->datalist(['dev', 'stage', 'master', 'prod'])
                        ->visible($this->nameInputVisible),

                    Forms\Components\Grid::make(6)
                        ->visible(fn (Forms\Get $get) => $get('name'))
                        ->schema([
                            $this->getParseAccessDataSection(),

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
                                ->columnSpan(6),

                            $this->generateServerConnectionSection()
                                ->columnSpan(6),

                            $this->generateMoreOptionsSections()
                                ->columnSpan(6)
                                ->collapsed(),

                            $this->generateDeployFileDownloadSection()
                                ->hidden()
                                ->columnSpan(6)
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

    protected function getParseAccessDataSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(fn (array $state) => str("Access data for **" . $state['name'] . "**")->markdown()->toHtmlString())
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
                    ->rows(15)
                    ->afterStateUpdated(function (?string $state, Forms\Get $get, Forms\Set $set, HasParserInfo $livewire) {
                        // reset data
                        $set('can_be_parsed', false);
                        $set('access_is_correct', false);

                        $set('server_connection_result', null);

                        $livewire->resetStatusForStage($get('name'));

                        if ($state) {
                            $this->tryToParseAccessInput($get('name'), $state);
                        }
                    }),
            ]);
    }

    protected function getDeployConfigurationSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(str('Deploy configuration `.yml` for all stages')->markdown()->toHtmlString())
            ->description('If you want, you can download the configuration file for all stages at once')
            ->hidden()
            // has at least one parsed stage
            ->visible(function (HasParserInfo $livewire, Forms\Get $get) {
                $defaultState = $livewire->hasOneParsedStage();

                $templateGroup = (new CiCdTemplateRepository())->getTemplateGroup($get('ci_cd_options.template_group'));

                return $defaultState && (is_null($templateGroup) || $templateGroup->isBackend());
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

    protected function getParseStageAccessAction(): Closure
    {
        return function (Forms\Get $get) {
            return Forms\Components\Actions\Action::make('parse-' . $get('name'))
                ->label(fn (array $state) => str("Parse access for **{$state['name']}**")->markdown()->toHtmlString())
                ->icon('heroicon-o-bolt')
                ->color(Color::Green)
                ->size(ActionSize::Small)
                ->action(action: function (Forms\Get $get, Forms\Set $set, HasParserInfo $livewire) {
                    $stageName = $get('name');

                    // reset data
                    $set('can_be_parsed', false);
                    $set('access_is_correct', false);

                    $set('server_connection_result', null);

                    $livewire->setConnectionStatusForStage($stageName, false);

                    $configurations = $this->retrieveConfigurations();

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

                    $notResolved = $parser->getNotResolved($stageName);

                    if (!empty($notResolved)) {
                        Notification::make()->title('You have unresolved data')->danger()->send();

                        $set('notResolved', $notResolved);
                    } else {
                        $set('contents.deploy_php', $parser->contentForDeployerScript($stageName));
                        $set('../../contents.deploy_yml', $parser->contentForDeployPrepareConfig($stageName));

                        $set('notResolved', null);
                    }

                    Notification::make()->title(
                        str("Stage **" . $stageName . "** successfully parsed!")->markdown()->toHtmlString()
                    )->success()->send();
                });
        };
    }

    protected function generateMoreOptionsSections(): Forms\Components\Section
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
                $this->getBashAliasesOptionsSection(),
            ]);
    }

    protected function getBashAliasesOptionsSection(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make()->schema([
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
        ]);
    }

    protected function generateDeployFileDownloadSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(fn (Forms\Get $get) => str("Generated `deploy.php` file for **{$get('name')}** stage")->markdown()->toHtmlString())
            ->description('You can download the generated `deploy.php` file and use it later')
            ->visible(function (Forms\Get $get, HasParserInfo $livewire) {
                $defaultState = $livewire->getParseStatusForStage($get('name')) && $this->sectionBasedOnDataCanBeVisible($get);

                $templateGroup = (new CiCdTemplateRepository())->getTemplateGroup($get('../../ci_cd_options.template_group'));

                return $defaultState && (is_null($templateGroup) || $templateGroup->isBackend());
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

    protected function generateParsedResultSection(): Forms\Components\Section
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

                        $this->fetchServerInfo($set, $livewire, $get);
                    }),
            ])
            ->schema(function (HasParserInfo $livewire) {
                return [
                    Forms\Components\Grid::make()->lazy()->schema([
                        Forms\Components\Grid::make(1)
                            ->columnSpan(1)
                            ->schema([
                                $livewire->getServerFieldsetWithInputs(),
                            ]),

                        Forms\Components\Grid::make(1)
                            ->columnSpan(1)
                            ->schema([
                                $livewire->getMySQLFieldsetWithInputs(),
                                $livewire->getSMTPFieldsetWithInputs(),
                            ]),
                    ])->afterStateUpdated(function () {
                        //
                    }),
                ];
            });
    }

    protected function generateServerConnectionSection(): Forms\Components\Section
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
                        $this->getSshConnectionInfoButton(),
                    ])->columnSpanFull()->alignCenter(),

                    Forms\Components\Grid::make()
                        ->columnSpanFull()
                        ->columns()
                        ->schema(function (Forms\Get $get) {
                            $templateInfo = ($group = $get('../../ci_cd_options.template_group'))
                                ? (new CiCdTemplateRepository())->getTemplateInfo($group, $get('../../ci_cd_options.template_key'))
                                : null;

                            $isBackend = (is_null($templateInfo) || $templateInfo->group->isBackend());

                            return [
                                Forms\Components\Fieldset::make('Server info')->columns(1)->columnSpan(1)->schema([
                                    Forms\Components\Placeholder::make('server_connection_result')
                                        ->label('')
                                        ->columnSpan(1)
                                        ->content(fn (Forms\Get $get) => str($get('server_connection_result') ?: 'not fetched yet')->toHtmlString()),
                                ]),

                                Forms\Components\Fieldset::make('Paths for deploymet')->columns(1)->columnSpan(1)->schema([
                                    Forms\Components\Textarea::make('options.base_dir_pattern')
                                        ->label('Deploy folder')
                                        ->rows(2)
                                        ->required(),
                                    Forms\Components\TextInput::make('options.home_folder')
                                        ->label('Home folder')
                                        ->required(),
                                    Forms\Components\TextInput::make('options.bin_php')
                                        ->label('bin/php')
                                        ->visible($isBackend)
                                        ->required($isBackend),
                                    Forms\Components\TextInput::make('options.bin_composer')
                                        ->label('bin/composer')
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

    protected function getSshConnectionInfoButton(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('fetch_server_info')
            ->label('Fetch server info')
            ->disabled(fn (Forms\Get $get) => empty($get('accessInfo.server.password')) && empty($get('options.ssh.private_key')))
            ->color(Color::Green)
            ->icon('heroicon-o-server')
            ->action(function (Forms\Get $get, Forms\Set $set, DeployConfigurator $livewire) {
                $this->fetchServerInfo($set, $livewire, $get);
            });
    }

    protected function fetchServerInfo(Forms\Set $set, HasParserInfo $livewire, Forms\Get $get): void
    {
        // reset data
        $set('server_connection_result', null);

        $livewire->setConnectionStatusForStage($get('name'), false);

        $isTest = data_get($livewire, 'data.projectInfo.is_test');

        $serverDetailParser = new ServerDetailParser(
            $server = new Server($get('accessInfo.server')),
            SshOptions::makeFromArray($get('options.ssh') ?: []),
        );

        try {
            $serverDetailParser->establishSSHConnection();
        } catch (DomainException $exception) {
            Notification::make()
                ->danger()
                ->title('Failed to establish SSH connection')
                ->body($exception->getMessage())
                ->send();

            return;
        }
        $livewire->setConnectionStatusForStage($get('name'), true);

        $templateGroup = (new CiCdTemplateRepository())->getTemplateGroup($get('../../ci_cd_options.template_group'));
        $isBackend = (is_null($templateGroup) || $templateGroup->isBackend());

        $serverDetailParser->setIsBackendServer($isBackend);
        $serverDetailParser->parse();

        $parseResult = $serverDetailParser->getParseResult();

        $testFolder = $isTest ? '/test' : '';

        // set server details options
        $domain = str($server->domain)->after('://')->value();
        $baseDir = in_array($get('name'), ['dev', 'stage'])
            ? "{$parseResult['homeFolderPath']}/web/{$domain}/public_html"
            : "{$parseResult['homeFolderPath']}/{$domain}/www";

        $newOptions = [
            'base_dir_pattern' => $baseDir . $testFolder,
            'home_folder' => $parseResult['homeFolderPath'] . $testFolder,
            ...($isBackend ? [
                'bin_php' => $parseResult['phpInfo']['bin'],
                'bin_composer' => "{$parseResult['phpInfo']['bin']} " . collect($parseResult['paths'])->get('composer')['bin'],
            ] : [
                'bin_php' => null,
                'bin_composer' => null,
            ]),
        ];

        foreach ($newOptions as $key => $newOption) {
            $set("options.{$key}", $newOption);
        }

        $info = collect([
            "<b>deploy folder:</b> {$baseDir}",
            "<b>home folder:</b> {$parseResult['homeFolderPath']}",
            ...($serverDetailParser->isBackend() ? [
                "",
                "<hr><b>bin paths:</b>",
                ...collect($parseResult['paths'])->map(fn ($path, $type) => "<b>{$type}:</b> {$path['bin']}"),
                "",
                "<hr><b>all php bins:</b> {$parseResult['phpInfo']['all']}",
                "",
                "<hr><b>php:</b> ({$parseResult['phpVersion']})",
                $parseResult['phpOutput'],
                "",
                "<hr><b>composer:</b> ({$parseResult['composerVersion']})",
                $parseResult['composerOutput'],
            ] : []),
        ])->implode('<br>');
        $set('server_connection_result', $info);

        Notification::make()->title('Server info fetched')->success()->send();
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
