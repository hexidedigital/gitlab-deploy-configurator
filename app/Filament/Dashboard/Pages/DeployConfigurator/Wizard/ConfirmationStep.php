<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Filament\Dashboard\Pages\DeployConfigurator;
use Filament\Forms;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\HtmlString;

class ConfirmationStep extends Forms\Components\Wizard\Step
{
    public static function make(string $label = ''): static
    {
        return parent::make('Confirmation');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-check-badge')
            ->afterValidation(function (Forms\Get $get, DeployConfigurator $livewire) {
                $project = $livewire->resolveProject($get('projectInfo.selected_id'));
                if (empty($project)) {
                    throw new Halt();
                }

                $isValid = $livewire->validateProject($project);
                if (!$isValid) {
                    throw new Halt();
                }
            })
            ->schema([
                Forms\Components\Section::make('Summary')
                    ->schema(function (DeployConfigurator $livewire) {
                        return [
                            Forms\Components\Fieldset::make('Project')
                                ->columns()
                                ->schema([
                                    Forms\Components\Placeholder::make('placeholder.name')
                                        ->label('Repository')
                                        ->content(fn (Forms\Get $get) => $get('projectInfo.name')),

                                    Forms\Components\Placeholder::make('placeholder.project_id')
                                        ->label('Project ID')
                                        ->content(fn (Forms\Get $get) => $get('projectInfo.project_id')),

                                    Forms\Components\Placeholder::make('placeholder.web_url')
                                        ->columnSpanFull()
                                        ->content(fn (Forms\Get $get) => new HtmlString(
                                            sprintf(
                                                '<a href="%s" class="underline" target="_blank">%s</a>',
                                                $get('projectInfo.web_url'),
                                                $get('projectInfo.web_url')
                                            )
                                        )),
                                ]),

                            Forms\Components\Fieldset::make('Repository and CI/CD')
                                ->columns()
                                ->schema([
                                    Forms\Components\Placeholder::make('placeholder.ci_cd_options.template_type')
                                        ->label('CI/CD template type')
                                        ->content(fn (Forms\Get $get) => $get('ci_cd_options.template_type')),

                                    Forms\Components\Placeholder::make('placeholder.ci_cd_options.template_version')
                                        ->label('CI/CD template version')
                                        ->content(fn (Forms\Get $get) => $get('ci_cd_options.template_version')),
                                ]),

                            Forms\Components\Repeater::make('stages')
                                ->itemLabel(fn (array $state) => $state['name'])
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->collapsible()
                                ->schema([
                                    Forms\Components\Grid::make()
                                        ->visible(fn (Forms\Get $get, DeployConfigurator $livewire) => $livewire->getParseStatusForStage($get('name')))
                                        ->schema([
                                            Forms\Components\Grid::make(1)
                                                ->columnSpan(1)
                                                ->schema([
                                                    $livewire->getServerFieldset(),
                                                    Forms\Components\Fieldset::make('Server details')
                                                        ->columns(1)
                                                        ->schema([
                                                            Forms\Components\Placeholder::make('placeholder.options.base_dir_pattern')
                                                                ->label('Base dir pattern')
                                                                ->content(fn (Forms\Get $get) => $get('options.base_dir_pattern')),
                                                            Forms\Components\Placeholder::make('placeholder.options.home_folder')
                                                                ->label('Home folder')
                                                                ->content(fn (Forms\Get $get) => $get('options.home_folder')),
                                                            Forms\Components\Placeholder::make('placeholder.options.bin_php')
                                                                ->label('bin/php')
                                                                ->content(fn (Forms\Get $get) => $get('options.bin_php')),
                                                            Forms\Components\Placeholder::make('placeholder.options.bin_composer')
                                                                ->label('bin/composer')
                                                                ->content(fn (Forms\Get $get) => $get('options.bin_composer')),
                                                        ]),
                                                ]),
                                            Forms\Components\Grid::make(1)
                                                ->columnSpan(1)
                                                ->schema([
                                                    $livewire->getMySQLFieldset(),
                                                    $livewire->getSMTPFieldset(),
                                                ]),
                                        ]),
                                ]),
                        ];
                    }),

                Forms\Components\Checkbox::make('is_ready_to_deploy')
                    ->label('I confirm that I have checked all the data and I am ready to deploy')
                    ->validationMessages([
                        'accepted' => 'You must confirm that you are ready to deploy.',
                    ])
                    ->accepted()
                    ->required(),
            ]);
    }
}
