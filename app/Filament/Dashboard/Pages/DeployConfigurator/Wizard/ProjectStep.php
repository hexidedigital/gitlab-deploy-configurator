<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\Data\CodeInfoDetails;
use App\Domains\GitLab\Data\ProjectData;
use App\Domains\GitLab\RepositoryParser;
use App\Filament\Actions\Forms\Components\CopyAction;
use App\Filament\Dashboard\Pages\DeployConfigurator;
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
                $project = $livewire->resolveProject($get('projectInfo.selected_id'));
                if (empty($project)) {
                    throw new Halt();
                }

                $isValid = $livewire->validateProject($project);
                if (!$isValid) {
                    throw new Halt();
                }
            })
            ->columns()
            ->schema([
                Forms\Components\Grid::make()->schema([
                    static::getProjectSelectComponent(),

                    Forms\Components\Toggle::make('projectInfo.is_test')
                        ->label('Is testing project')
                        ->columnSpan(1)
                        ->visible(fn () => auth()->user()->isRoot()),
                ]),

                static::getScriptForInitSection(),

                static::getProjectInfoGrid(),
            ]);
    }

    protected static function getProjectSelectComponent(): Forms\Components\Select
    {
        return Forms\Components\Select::make('projectInfo.selected_id')
            ->label('Project')
            ->placeholder('Select project...')
            ->required()
            ->live()
            ->searchable()
            ->columnSpan(1)
            ->getSearchResultsUsing(function (string $search, DeployConfigurator $livewire) {
                return $livewire->gitlabService()->ignoreOnGitlabException()->fetchProjectFromGitLab([
                    'search' => $search,
                ])->map(fn (ProjectData $project) => $project->getNameForSelect());
            })
            ->getOptionLabelUsing(function (?string $value, DeployConfigurator $livewire) {
                $project = $livewire->gitlabService()->ignoreOnGitlabException()->findProject($value);
                if (is_null($project)) {
                    return $value;
                }

                return $project->getNameForSelect();
            })
            ->options(function (DeployConfigurator $livewire) {
                return $livewire->gitlabService()->ignoreOnGitlabException()->fetchProjectFromGitLab()
                    ->map(fn (ProjectData $project) => $project->getNameForSelect());
            })
            ->afterStateUpdated(function (Forms\Get $get, DeployConfigurator $livewire) {
                $livewire->resetProjectRelatedData();
                $livewire->selectProject($get('projectInfo.selected_id'));
            });
    }

    protected static function getScriptForInitSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('empty_repository')
            ->visible(fn (DeployConfigurator $livewire) => $livewire->emptyRepo)
            ->collapsible()
            ->icon('heroicon-o-exclamation-circle')
            ->iconColor(Color::Red)
            ->heading('Empty repository detected!')
            ->description('This repository is empty. To continue, you must manually push the initial commit to the repository.')
            ->schema([
                Forms\Components\Textarea::make('init_repository_script')
                    ->hiddenLabel()
                    ->readOnly()
                    ->extraInputAttributes([
                        'rows' => 8,
                        'class' => 'font-mono',
                    ])
                    ->hintAction(CopyAction::make('init_repository_script')),
            ])
            ->footerActionsAlignment(Alignment::End)
            ->footerActions([
                // refresh button
                Forms\Components\Actions\Action::make('refresh-project')
                    ->label('Refresh')
                    ->icon('heroicon-s-arrow-path')
                    ->color(Color::Indigo)
                    ->action(function (Forms\Get $get, DeployConfigurator $livewire) {
                        $livewire->resetProjectRelatedData();
                        $livewire->selectProject($get('projectInfo.selected_id'));
                    }),
            ]);
    }

    public static function getProjectInfoGrid(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make()->schema([
            Forms\Components\Grid::make(1)->columnSpan(1)->schema([
                static::getProjectInfoFieldset(),
            ]),

            Forms\Components\Fieldset::make('Code')->columns(1)->columnSpan(1)
                ->visible(function (Forms\Get $get) {
                    $codeInfoDetails = CodeInfoDetails::makeFromArray($get('projectInfo.codeInfo') ?: []);

                    return $get('projectInfo.selected_id') && ($codeInfoDetails->isLaravel || $codeInfoDetails->isNode);
                })
                ->schema(function (Forms\Get $get) {
                    $codeInfoDetails = CodeInfoDetails::makeFromArray($get('projectInfo.codeInfo') ?: []);

                    return match (true) {
                        $codeInfoDetails->isLaravel => static::getCodeInfoSchemaForLaravelProject(),
                        $codeInfoDetails->isNode => static::getCodeInfoSchemaForNodeProject(),
                        default => [],
                    };
                }),
        ]);
    }

    protected static function getProjectInfoFieldset(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('Repository')->columns()->columnSpan(1)->visible(fn (Forms\Get $get) => $get('projectInfo.selected_id'))->schema([
            Forms\Components\Placeholder::make('placeholder.name')
                ->content(fn (Forms\Get $get) => $get('projectInfo.name')),

            Forms\Components\Placeholder::make('placeholder.project_id')
                ->label('Project ID')
                ->content(fn (Forms\Get $get) => $get('projectInfo.project_id')),

            Forms\Components\Placeholder::make('placeholder.access_level')
                ->label('Access level')
                ->content(function (Forms\Get $get, DeployConfigurator $livewire) {
                    $project = $livewire->gitlabService()->ignoreOnGitlabException()->findProject($get('projectInfo.selected_id'));

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
        ]);
    }

    protected static function getCodeInfoSchemaForNodeProject(): array
    {
        return [
            Forms\Components\Placeholder::make('placeholder.codeInfo.repository_type')
                ->label('Project type')
                ->content(fn (Forms\Get $get) => (new CiCdTemplateRepository())->getTemplateGroup($get('ci_cd_options.template_group'))?->nameAndIcon() ?: 'not resolved'),

            Forms\Components\Placeholder::make('placeholder.codeInfo.repository_template_group')
                ->label('Detected project template')
                ->content(fn (Forms\Get $get) => str($get('projectInfo.codeInfo.repository_template'))->title()),

            Forms\Components\Placeholder::make('placeholder.codeInfo.framework_version')
                ->label('Detected version for framework')
                ->visible(fn (Forms\Get $get) => $get('projectInfo.codeInfo.framework_version'))
                ->content(fn (Forms\Get $get) => $get('projectInfo.codeInfo.framework_version')),

            Forms\Components\Placeholder::make('placeholder.codeInfo.frontend_builder')
                ->label('Frontend builder')
                ->visible(fn (Forms\Get $get) => $get('projectInfo.codeInfo.frontend_builder'))
                ->content(fn (Forms\Get $get) => $get('projectInfo.codeInfo.frontend_builder')),
        ];
    }

    protected static function getCodeInfoSchemaForLaravelProject(): array
    {
        return [
            Forms\Components\Placeholder::make('placeholder.codeInfo.repository_type')
                ->label('Project type')
                ->content(fn (Forms\Get $get) => (new CiCdTemplateRepository())->getTemplateGroup($get('ci_cd_options.template_group'))?->nameAndIcon() ?: 'not resolved'),

            Forms\Components\Placeholder::make('placeholder.codeInfo.repository_template_group')
                ->label('Detected project template')
                ->content(fn (Forms\Get $get) => RepositoryParser::getRepositoryTemplateName($get('projectInfo.codeInfo.repository_template'))),

            Forms\Components\Placeholder::make('placeholder.codeInfo.framework_version')
                ->label('Detected laravel')
                ->visible(fn (Forms\Get $get) => $get('projectInfo.codeInfo.framework_version'))
                ->content(fn (Forms\Get $get) => $get('projectInfo.codeInfo.framework_version')),

            Forms\Components\Placeholder::make('placeholder.codeInfo.admin_panel')
                ->label('Detected Admin panel')
                ->visible(fn (Forms\Get $get) => $get('projectInfo.codeInfo.admin_panel'))
                ->content(fn (Forms\Get $get) => $get('projectInfo.codeInfo.admin_panel')),

            Forms\Components\Placeholder::make('placeholder.codeInfo.frontend_builder')
                ->label('Frontend builder')
                ->visible(fn (Forms\Get $get) => $get('projectInfo.codeInfo.frontend_builder'))
                ->content(fn (Forms\Get $get) => $get('projectInfo.codeInfo.frontend_builder')),
        ];
    }
}
