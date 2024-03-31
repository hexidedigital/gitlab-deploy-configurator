<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use App\Filament\Actions\Forms\Components\CopyAction;
use App\Filament\Contacts\HasParserInfo;
use App\Parser\DeployConfigBuilder;
use Closure;
use Exception;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\Alignment;
use Throwable;

class ParseAccessSchema extends Forms\Components\Grid
{
    protected Closure $retrieveConfigurationsUsing;

    protected ?Closure $modifyStageRepeaterUsing = null;
    protected bool|Closure $confirmationCheckboxVisible = true;
    protected bool|Closure $nameInputVisible = false;

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
            ->schema(function () {
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

                    Forms\Components\Grid::make(5)
                        ->visible(fn (Forms\Get $get) => $get('name'))
                        ->schema([
                            Forms\Components\Section::make(fn (array $state) => str("Access data for **" . $state['name'] . "** stage")->markdown()->toHtmlString())
                                ->columnSpan(2)
                                ->footerActions([
                                    $this->getParseStageAccessAction(),
                                ])
                                ->footerActionsAlignment(Alignment::End)
                                ->collapsible()
                                ->persistCollapsed(false)
                                ->collapsed(fn (Forms\Get $get, HasParserInfo $livewire) => $livewire->getParseStatusForStage($get('name')))
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
                                            $set('can_be_parsed', false);
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
                                ->columnSpan(3)
                                ->visible(fn (Forms\Get $get) => !is_null($get('notResolved')))
                                ->schema(function (Forms\Get $get) {
                                    return collect($get('notResolved'))->map(function ($info) {
                                        return Forms\Components\Placeholder::make('not-resolved-chunk.' . $info['chunk'])
                                            ->label('Section #' . $info['chunk'])
                                            ->content(str(collect($info['lines'])->map(fn ($line) => "- {$line}")->implode(PHP_EOL))->markdown()->toHtmlString());
                                    })->all();
                                }),

                            $this->generateParsedResultSection(),

                            $this->generateDeployFileDownloadSection(),
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
        return Forms\Components\Section::make('Deploy configuration (for all stages)')
            ->description('If you want, you can download the configuration file for all stages at once')
            // has at least one parsed stage
            ->visible(fn (HasParserInfo $livewire) => $livewire->hasParsedStage())
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
                        ->action(function (ParseAccessSchema $component) {
                            $configurations = $component->retrieveConfigurations();

                            $deployConfigBuilder = new DeployConfigBuilder();
                            $deployConfigBuilder->parseConfiguration($configurations);

                            $deployConfigBuilder->buildDeployPrepareConfig();

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
                    $configurations = $this->retrieveConfigurations();

                    $stageName = $get('name');
                    $parser = $this->tryToParseAccessInput($stageName, $get('access_input'));
                    if (!$parser) {
                        return;
                    }

                    $parser->parseConfiguration($configurations);

                    $parser->buildDeployPrepareConfig($stageName);

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

    public function generateDeployFileDownloadSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(str('Generated **deploy** file (for current stage)')->markdown()->toHtmlString())
            ->visible(fn (Forms\Get $get, HasParserInfo $livewire) => $livewire->getParseStatusForStage($get('name')))
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

                                    $parser->buildDeployPrepareConfig();

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
        return Forms\Components\Section::make('Parsed result')
            ->visible(fn (Forms\Get $get, HasParserInfo $livewire) => $livewire->getParseStatusForStage($get('name')))
            ->columnSpanFull()
            ->collapsible()
            ->columns(3)
            ->schema(function (HasParserInfo $livewire) {
                return [
                    Forms\Components\Checkbox::make('access_is_correct')
                        ->label('I agree that the access data is correct')
                        ->accepted()
                        ->visible($this->confirmationCheckboxVisible)
                        ->columnSpanFull()
                        ->validationMessages([
                            'accepted' => 'Accept this field',
                        ])
                        ->required(),

                    $livewire->getServerFieldset(),
                    $livewire->getMySQLFieldset(),
                    $livewire->getSMTPFieldset(),
                ];
            });
    }

    public function showConfirmationCheckbox(bool|Closure $callback = true): static
    {
        return $this->tap(fn () => $this->confirmationCheckboxVisible = $callback);
    }

    protected function tryToParseAccessInput(string $stageName, ?string $accessInput): ?DeployConfigBuilder
    {
        try {
            return (new DeployConfigBuilder())->parseInputForAccessPayload($stageName, $accessInput);
        } catch (Throwable $e) {
            Notification::make()->title('Invalid content')->body($e->getMessage())->danger()->send();

            return null;
        }
    }
}
