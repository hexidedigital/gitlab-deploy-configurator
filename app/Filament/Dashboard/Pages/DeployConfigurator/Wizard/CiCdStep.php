<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Filament\Dashboard\Pages\DeployConfigurator;
use Filament\Forms;
use Filament\Support\Colors\Color;
use Gitlab\Exception\RuntimeException;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class CiCdStep extends Forms\Components\Wizard\Step
{
    protected CiCdTemplateRepository $templateRepository;

    public static function make(string $label = ''): static
    {
        return parent::make('CI/CD details');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateRepository = new CiCdTemplateRepository();

        $this
            ->icon('heroicon-o-server')
            ->columns()
            ->schema([
                Forms\Components\Select::make('ci_cd_options.template_type')
                    ->label('Template types')
                    ->columnSpan(1)
                    ->live()
                    ->options($this->templateRepository->templateTypes())
                    ->disableOptionWhen(function (string $value, Forms\Get $get) {
                        if ($get('data.projectInfo.codeInfo.is_laravel')) {
                            return $value !== 'backend';
                        }

                        return $value === 'backend';
                    })
                    ->helperText(
                        new HtmlString('See more details about <a href="https://gitlab.hexide-digital.com/packages/gitlab-templates" class="underline" target="_blank">GitLab Templates</a>')
                    )
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $set('ci_cd_options.template_version', null);
                    })
                    ->required(),

                Forms\Components\Select::make('ci_cd_options.template_version')
                    ->label('CI/CD template version')
                    ->columnSpan(1)
                    ->visible(fn (Forms\Get $get) => $get('ci_cd_options.template_type'))
                    ->live()
                    ->options(fn (Forms\Get $get) => $this->retrieveCiCdTemplates($get('ci_cd_options.template_type'))->map(fn (array $template) => $template['name'])->all())
                    ->disableOptionWhen(fn (string $value, Forms\Get $get) => data_get($this->retrieveCiCdTemplates($get('ci_cd_options.template_type')), "$value.disabled", true))
                    ->required(),

                $this->getCiCdStagesToggleFieldSet(),

                $this->getNodeVersionSelect(),

                $this->getStagesRepeater(),
            ]);
    }

    protected function getCiCdStagesToggleFieldSet(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('Enabled CI\CD stages')
            ->visible(fn (Forms\Get $get) => data_get($this->retrieveCiCdTemplates($get('ci_cd_options.template_type')), $get('ci_cd_options.template_version') . '.configure_stages'))
            ->columns(3)
            ->columnSpanFull()
            ->reactive()
            ->schema([
                Forms\Components\Toggle::make('ci_cd_options.enabled_stages.prepare')
                    ->label('Prepare (composer)')
                    ->onColor(Color::Purple)
                    ->onIcon('fab-php')
                    ->offIcon('fab-php')
                    ->helperText('Installs vendor dependencies'),
                Forms\Components\Toggle::make('ci_cd_options.enabled_stages.build')
                    ->label('Build')
                    ->onColor(Color::Green)
                    ->onIcon('fab-node-js')
                    ->offIcon('fab-node-js')
                    ->helperText('Builds assets'),
                Forms\Components\Toggle::make('ci_cd_options.enabled_stages.deploy')
                    ->label('Deploy')
                    ->onColor(Color::Orange)
                    ->onIcon('fab-gitlab')
                    ->helperText('Deploys to server')
                    ->disabled(),
            ]);
    }

    protected function getNodeVersionSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('ci_cd_options.node_version')
            ->label('Node.js version')
            ->visible(function (Forms\Get $get) {
                return ($get('ci_cd_options.enabled_stages.build') || $get('ci_cd_options.template_type') === 'frontend')
                    && data_get($this->retrieveCiCdTemplates($get('ci_cd_options.template_type')), $get('ci_cd_options.template_version') . '.change_node_version');
            })
            ->columnSpan(1)
            ->options([
                '20' => '20',
                '18' => '18',
                '16' => '16',
                '14' => '14',
                '12' => '12',
            ])
            ->required(fn (Forms\Get $get) => $get('ci_cd_options.enabled_stages.build'));
    }

    protected function getStagesRepeater(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('Add stages to deploy')
            ->statePath('stages')
            ->addActionLabel('Add new stage')
            ->columnSpanFull()
            ->itemLabel(fn (array $state) => str($state['name'] ? "Add **" . $state['name'] . "** stage" : 'Adding new stage...')->markdown()->toHtmlString())
            ->reorderable(false)
            ->minItems(1)
            ->required()
            ->validationMessages([
                'required' => 'At least one stage is required.',
                'min' => 'At least one stage is required.',
            ])
            ->grid()
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->live(onBlur: true)
                    ->label('Stage name (branch name)')
                    ->hiddenLabel()
                    ->placeholder('dev/stage/master/prod')
                    ->distinct()
                    ->notIn(function (DeployConfigurator $livewire, Forms\Get $get) {
                        if ($get('force_deploy')) {
                            return [];
                        }

                        // prevent adding existing branch
                        $branches = $livewire->getGitLabManager()->repositories()->branches($get('../../projectInfo.selected_id'));

                        $currentBranches = collect($branches)->map(fn ($branch) => $branch['name']);

                        return $currentBranches->toArray();
                    })
                    ->validationMessages([
                        'distinct' => 'Name cannot be duplicated.',
                        'not_in' => 'Branch name already exists.',
                    ])
                    ->datalist([
                        'dev',
                        'stage',
                        'master',
                        'prod',
                    ])
                    ->required(),

                // when branch exists, force deploy to this branch
                Forms\Components\Checkbox::make('force_deploy')
                    ->label('This branch exists. Force deploy to this branch?')
                    ->visible(function (Forms\Get $get, DeployConfigurator $livewire) {
                        try {
                            $branches = $livewire->getGitLabManager()->repositories()->branches($get('../../projectInfo.selected_id'));
                        } catch (RuntimeException) {
                            return false;
                        }

                        $currentBranches = collect($branches)->map(fn ($branch) => $branch['name']);

                        return $currentBranches->contains($get('name'));
                    })
                    ->columnSpan(1),
            ]);
    }

    protected function retrieveCiCdTemplates(?string $type): Collection
    {
        return collect($this->templateRepository->getTemplatesForType($type));
    }
}
