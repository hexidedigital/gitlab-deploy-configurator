<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
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
                Forms\Components\Toggle::make('refresh')->live(),

                Forms\Components\Section::make('Summary')
                    ->schema(function (DeployConfigurator $livewire, Forms\Get $get) {
                        $templateInfo = (new CiCdTemplateRepository())->getTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'));

                        return [
                            Forms\Components\Grid::make()
                                ->columns()
                                ->schema([ProjectStep::getProjectInfoGrid()]),

                            Forms\Components\Fieldset::make('Repository and CI/CD')
                                ->columns()
                                ->schema([
                                    Forms\Components\Placeholder::make('placeholder.ci_cd_options.template_group')
                                        ->label('CI/CD template type')
                                        ->content($templateInfo->group->nameAndIcon()),

                                    Forms\Components\Placeholder::make('placeholder.ci_cd_options.template_key')
                                        ->label('CI/CD template version')
                                        ->content($templateInfo->name),
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
                                                    Forms\Components\Fieldset::make('Options for deployment')
                                                        ->columns(1)
                                                        ->schema([
                                                            Forms\Components\Placeholder::make('placeholder.options.base_dir_pattern')
                                                                ->label('Deploy folder')
                                                                ->content(fn (Forms\Get $get) => $get('options.base_dir_pattern')),
                                                            Forms\Components\Placeholder::make('placeholder.options.home_folder')
                                                                ->label('Home folder')
                                                                ->content(fn (Forms\Get $get) => $get('options.home_folder')),
                                                            Forms\Components\Placeholder::make('placeholder.options.bin_php')
                                                                ->label('bin/php')
                                                                ->visible($templateInfo->group->isBackend())
                                                                ->content(fn (Forms\Get $get) => $get('options.bin_php')),
                                                            Forms\Components\Placeholder::make('placeholder.options.bin_composer')
                                                                ->label('bin/composer')
                                                                ->visible($templateInfo->group->isBackend())
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
