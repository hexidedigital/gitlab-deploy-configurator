<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Filament\Actions\Forms\Components\CopyAction;
use App\Filament\Dashboard\Pages\DeployConfigurator;
use App\GitLab\Data\ProjectData;
use Filament\Forms;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\HtmlString;

class ProjectStep extends Forms\Components\Wizard\Step
{
    public static function make(string $label = ''): static
    {
        return parent::make('Project');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-bolt')
            ->afterValidation(function (Forms\Get $get, DeployConfigurator $livewire) {
                $isValid = $livewire->validateProjectData($get('projectInfo.selected_id'));
                if (!$isValid) {
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
                    ->getSearchResultsUsing(function (string $search, DeployConfigurator $livewire) {
                        return $livewire->fetchProjectFromGitLab([
                            'search' => $search,
                        ])->map(fn (ProjectData $project) => $project->getNameForSelect());
                    })
                    ->options(function (DeployConfigurator $livewire) {
                        return $livewire->fetchProjectFromGitLab()
                            ->map(fn (ProjectData $project) => $project->getNameForSelect());
                    })
                    ->afterStateUpdated(function (Forms\Get $get, DeployConfigurator $livewire) {
                        $livewire->selectProject($get('projectInfo.selected_id'));
                    }),

                Forms\Components\Section::make('empty_repository')
                    ->visible(fn (DeployConfigurator $livewire) => $livewire->emptyRepo)
                    ->collapsible()
                    ->icon('heroicon-o-exclamation-circle')
                    ->iconColor(Color::Red)
                    ->heading('Empty repository detected!')
                    ->description('This repository is empty. To continue, you must manually push the initial commit to the repository.')
                    ->schema([
                        Forms\Components\Textarea::make('init_repository')
                            ->hiddenLabel()
                            ->readOnly()
                            ->extraInputAttributes([
                                'rows' => 8,
                                'class' => 'font-mono',
                            ])
                            ->hintAction(CopyAction::make('init_repository')),
                    ])
                    ->footerActionsAlignment(Alignment::End)
                    ->footerActions([
                        // refresh button
                        Forms\Components\Actions\Action::make('refresh-project')
                            ->label('Refresh')
                            ->icon('heroicon-s-arrow-path')
                            ->color(Color::Indigo)
                            ->action(function (Forms\Get $get, DeployConfigurator $livewire) {
                                $livewire->selectProject($get('projectInfo.selected_id'));
                            }),
                    ]),

                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\Grid::make(1)
                            ->columnSpan(1)
                            ->schema([
                                Forms\Components\Fieldset::make('Repository')
                                    ->columns()
                                    ->columnSpan(1)
                                    ->schema([
                                        Forms\Components\Placeholder::make('placeholder.name')
                                            ->content(fn (Forms\Get $get) => $get('projectInfo.name')),

                                        Forms\Components\Placeholder::make('placeholder.project_id')
                                            ->label('Project ID')
                                            ->content(fn (Forms\Get $get) => $get('projectInfo.project_id')),

                                        Forms\Components\Placeholder::make('placeholder.access_level')
                                            ->label('Access level')
                                            ->content(function (Forms\Get $get, DeployConfigurator $livewire) {
                                                $project = $livewire->findProject($get('projectInfo.selected_id'));

                                                if (is_null($project)) {
                                                    return '-';
                                                }

                                                return $project->level()->getLabel();
                                            }),

                                        Forms\Components\Placeholder::make('placeholder.web_url')
                                            ->columnSpanFull()
                                            ->content(fn (Forms\Get $get) => new HtmlString(
                                                sprintf(
                                                    '<a href="%s" class="underline" target="_blank">%s</a>',
                                                    $get('projectInfo.web_url'),
                                                    $get('projectInfo.web_url')
                                                )
                                            )),

                                        Forms\Components\Placeholder::make('placeholder.git_url')
                                            ->columnSpanFull()
                                            ->label('Git url')
                                            ->content(fn (Forms\Get $get) => $get('projectInfo.git_url')),
                                    ]),
                            ]),
                        Forms\Components\Fieldset::make('Code')
                            ->columns(1)
                            ->columnSpan(1)
                            ->schema([
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
                            ]),
                    ]),
            ]);
    }
}
