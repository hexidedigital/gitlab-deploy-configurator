<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\Data\CiCdOptions;
use App\Domains\DeployConfigurator\Data\CodeInfoDetails;
use App\Domains\DeployConfigurator\Data\TemplateGroup;
use App\Domains\DeployConfigurator\Data\TemplateInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator;
use Filament\Forms;
use Filament\Support\Colors\Color;
use Gitlab\Exception\RuntimeException;
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
                Forms\Components\Select::make('ci_cd_options.template_group')
                    ->label('Template types')
                    ->columnSpan(1)
                    ->live()
                    ->options(
                        collect($this->templateRepository->getTemplateGroups())
                            ->mapWithKeys(fn (TemplateGroup $group) => [
                                $group->key => $group->nameAndIcon(),
                            ])
                    )
                    ->disableOptionWhen(function (string $value, Forms\Get $get) {
                        if ($get('projectInfo.is_test')) {
                            return false;
                        }

                        $codeInfoDetails = CodeInfoDetails::makeFromArray($get('projectInfo.codeInfo') ?: []);

                        if ($codeInfoDetails->isLaravel) {
                            return $value !== TemplateGroup::Backend;
                        }

                        if ($codeInfoDetails->isNode) {
                            return $value !== TemplateGroup::Frontend;
                        }

                        return true;
                    })
                    ->helperText(
                        new HtmlString('See more details about <a href="https://gitlab.hexide-digital.com/packages/gitlab-templates" class="underline" target="_blank">GitLab Templates</a>')
                    )
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $set('ci_cd_options.template_key', null);
                    })
                    ->required(),

                Forms\Components\Select::make('ci_cd_options.template_key')
                    ->label('CI/CD template version')
                    ->columnSpan(1)
                    ->visible(fn (Forms\Get $get) => $get('ci_cd_options.template_group'))
                    ->live()
                    ->options(fn (Forms\Get $get) => collect($this->templateRepository->getTemplatesForGroup($get('ci_cd_options.template_group')))
                        ->map(fn (TemplateInfo $template) => $template->name)
                        ->all())
                    ->disableOptionWhen(fn (string $value, Forms\Get $get) => $this->getSelectedTemplateInfo($get('ci_cd_options.template_group'), $value)?->isDisabled)
                    ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, DeployConfigurator $livewire) {
                        $templateInfo = $this->getSelectedTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'));
                        $project = $livewire->gitlabService()->ignoreOnGitlabException()->findProject($get('projectInfo.selected_id'));

                        if (!is_null($templateInfo?->preferredBuildFolder())) {
                            $set('ci_cd_options.build_folder', $templateInfo->preferredBuildFolder());
                        }

                        if (!is_null($templateInfo?->preferredBuildFolder())) {
                            $set('ci_cd_options.extra.pm2_name', str($project->name)->after('.')->beforeLast('.')->lower()->snake()->value());
                        }
                    })
                    ->required(),

                $this->getCiCdStagesToggleFieldSet(),

                $this->getNodeVersionSelect(),

                Forms\Components\TextInput::make('ci_cd_options.build_folder')
                    ->label('Build folder')
                    ->helperText('Which folder will be copies to server with rsync')
                    ->columnSpan(1)
                    ->datalist(['dist', 'build'])
                    ->visible(fn (Forms\Get $get) => $this->getSelectedTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'))?->hasBuildFolder)
                    ->required(fn (Forms\Get $get) => $this->getSelectedTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'))?->hasBuildFolder),

                Forms\Components\TextInput::make('ci_cd_options.extra.pm2_name')
                    ->label('App name for PM2')
                    ->columnSpan(1)
                    ->visible(fn (Forms\Get $get) => $this->getSelectedTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'))?->usesPM2())
                    ->required(fn (Forms\Get $get) => $this->getSelectedTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'))?->usesPM2()),

                $this->getStagesRepeater(),
            ]);
    }

    protected function getCiCdStagesToggleFieldSet(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('Enabled CI\CD stages')
            ->visible(fn (Forms\Get $get) => $this->getSelectedTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'))?->allowToggleStages)
            ->columns(3)
            ->columnSpanFull()
            ->reactive()
            ->schema([
                Forms\Components\Toggle::make('ci_cd_options.enabled_stages.' . CiCdOptions::PrepareStage)
                    ->label('Prepare (composer)')
                    ->onColor(Color::Purple)
                    ->onIcon('fab-php')
                    ->offIcon('fab-php')
                    ->helperText('Installs vendor dependencies'),
                Forms\Components\Toggle::make('ci_cd_options.enabled_stages.' . CiCdOptions::BuildStage)
                    ->label('Build')
                    ->onColor(Color::Green)
                    ->onIcon('fab-node-js')
                    ->offIcon('fab-node-js')
                    ->helperText('Builds assets'),
                Forms\Components\Toggle::make('ci_cd_options.enabled_stages.' . CiCdOptions::DeployStage)
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
                return $this->canSelectNodeVersion(
                    $get,
                    $this->getSelectedTemplateInfo($get('ci_cd_options.template_group'), $get('ci_cd_options.template_key'))
                );
            })
            ->columnSpan(1)
            ->options([
                '22' => '22',
                '20' => '20',
                '18' => '18',
                '16' => '16',
                '14' => '14',
            ])
            ->required(fn (Forms\Get $get) => $get('ci_cd_options.enabled_stages.' . CiCdOptions::BuildStage));
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

    protected function canSelectNodeVersion(Forms\Get $get, ?TemplateInfo $templateInfo): bool
    {
        return ($get('ci_cd_options.enabled_stages.' . CiCdOptions::BuildStage) || $get('ci_cd_options.template_group') === TemplateGroup::Frontend)
            && $templateInfo?->canSelectNodeVersion;
    }

    protected function getSelectedTemplateInfo(?string $group, ?string $key): ?TemplateInfo
    {
        return collect($this->templateRepository->getTemplatesForGroup($group))->get($key);
    }
}
