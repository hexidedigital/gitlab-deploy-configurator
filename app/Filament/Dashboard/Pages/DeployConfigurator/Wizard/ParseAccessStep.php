<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Filament\Actions\Forms\Components\CopyAction;
use App\Filament\Dashboard\Pages\DeployConfigurator;
use App\Parser\DeployConfigBuilder;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\Alignment;
use Filament\Support\Exceptions\Halt;
use Throwable;

class ParseAccessStep extends Forms\Components\Wizard\Step
{
    public static function make(string $label = ''): static
    {
        return parent::make('Parse access');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-key')
            ->afterValidation(function (DeployConfigurator $livewire) {
                $parsed = collect($livewire->parsed);

                $isNotParsedAllAccesses = $parsed->isEmpty()
                    || $parsed->reject()->isNotEmpty();

                if ($isNotParsedAllAccesses) {
                    Notification::make()->title('You have unresolved access data')->danger()->send();
                    throw new Halt();
                }
            })
            ->schema([
                Forms\Components\Repeater::make('stages')
                    ->hiddenLabel()
                    ->itemLabel(fn (array $state) => str("Manage **" . $state['name'] . "** stage")->markdown()->toHtmlString())
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->collapsible()
                    ->schema(function (DeployConfigurator $livewire) {
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
                                                ->action(function (Forms\Get $get, Forms\Set $set, DeployConfigurator $livewire) {
                                                    $configurations = $livewire->form->getRawState();

                                                    $parser = $this->tryToParseAccessInput($get('name'), $get('access_input'));
                                                    if (!$parser) {
                                                        return;
                                                    }

                                                    $parser->setConfigurations($configurations);

                                                    $parser->buildDeployPrepareConfig($get('name'));

                                                    $set('can_be_parsed', true);
                                                    $livewire->fill([
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
                                    ->collapsed(fn (Forms\Get $get, DeployConfigurator $livewire) => data_get($livewire, 'parsed.' . $get('name')))
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
                                            ->afterStateUpdated(function (?string $state, Forms\Get $get, Forms\Set $set, DeployConfigurator $livewire) {
                                                $set('can_be_parsed', false);
                                                $livewire->fill([
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
                                    ->visible(fn (Forms\Get $get, DeployConfigurator $livewire) => data_get($livewire, 'parsed.' . $get('name')))
                                    ->columnSpanFull()
                                    ->collapsible()
                                    ->columns(3)
                                    ->schema([
                                        Forms\Components\Checkbox::make('access_is_correct')
                                            ->label('I agree that the access data is correct')
                                            ->accepted()
                                            ->columnSpanFull()
                                            ->validationMessages([
                                                'accepted' => 'Accept this field',
                                            ])
                                            ->required(),

                                        $livewire->getServerFieldset(),
                                        $livewire->getMySQLFieldset(),
                                        $livewire->getSMTPFieldset(),
                                    ]),

                                Forms\Components\Section::make(str('Generated **deploy** file (for current stage)')->markdown()->toHtmlString())
                                    ->visible(fn (Forms\Get $get, DeployConfigurator $livewire) => data_get($livewire, 'parsed.' . $get('name')))
                                    ->collapsed()
                                    ->schema(function (Forms\Get $get) {
                                        return [
                                            Forms\Components\Grid::make(1)->columnSpan(1)->schema([
                                                Forms\Components\Textarea::make('contents.deploy_php')
                                                    ->label(str('`deploy.php`')->markdown()->toHtmlString())
                                                    ->hintAction(
                                                        CopyAction::make('copyResult.' . $get('name'))
                                                            ->visible(fn (DeployConfigurator $livewire) => data_get($livewire, 'parsed.' . $get('name')))
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
                                                        ->action(function (Forms\Get $get, DeployConfigurator $livewire) {
                                                            $configurations = $livewire->form->getRawState();

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
                    ->description('If you want, you can download the configuration file for all stages at once')
                    // has at least one parsed stage
                    ->visible(fn (DeployConfigurator $livewire) => collect($livewire->parsed)->filter()->isNotEmpty())
                    ->collapsed()
                    ->schema([
                        Forms\Components\Grid::make(1)->columnSpan(1)->schema([
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
                                    ->action(function (DeployConfigurator $livewire) {
                                        $configurations = $livewire->form->getRawState();

                                        $deployConfigBuilder = new DeployConfigBuilder();
                                        $deployConfigBuilder->setConfigurations($configurations);

                                        $deployConfigBuilder->buildDeployPrepareConfig();

                                        $path = $deployConfigBuilder->makeDeployPrepareYmlFile();

                                        return response()->download($path)->deleteFileAfterSend();
                                    }),
                            ])->extraAttributes(['class' => 'items-end']),
                        ]),
                    ]),
            ]);
    }

    protected function tryToParseAccessInput(string $stageName, string $accessInput): ?DeployConfigBuilder
    {
        try {
            return (new DeployConfigBuilder())->parseInputForAccessPayload($stageName, $accessInput);
        } catch (Throwable $e) {
            Notification::make()->title('Invalid content')->body($e->getMessage())->danger()->send();

            return null;
        }
    }
}
