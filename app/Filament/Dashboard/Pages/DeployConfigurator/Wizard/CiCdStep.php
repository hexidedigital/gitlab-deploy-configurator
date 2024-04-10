<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Filament\Dashboard\Pages\DeployConfigurator;
use Filament\Forms;
use Gitlab\Exception\RuntimeException;
use Illuminate\Support\HtmlString;

class CiCdStep extends Forms\Components\Wizard\Step
{
    public static function make(string $label = ''): static
    {
        return parent::make('CI/CD details');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-server')
            ->columns()
            ->schema([
                Forms\Components\Select::make('ci_cd_options.template_version')
                    ->label('CI/CD template version')
                    ->columnSpan(1)
                    ->live()
                    ->options($this->getCiCdTemplateVersions())
                    ->disableOptionWhen(fn (string $value) => !$this->isCiCdTemplateVersionAvailable($value))
                    ->helperText(
                        new HtmlString('See more details about <a href="https://gitlab.hexide-digital.com/packages/gitlab-templates#template-versions" class="underline" target="_blank">template-versions</a>')
                    )
                    ->required(),

                Forms\Components\Fieldset::make('Enabled CI\CD stages')
                    ->visible(fn (Forms\Get $get) => $get('ci_cd_options.template_version') === '3.0')
                    ->columns(3)
                    ->columnSpanFull()
                    ->reactive()
                    ->schema([
                        Forms\Components\Checkbox::make('ci_cd_options.enabled_stages.prepare')
                            ->label('Prepare (composer)')
                            ->helperText('Installs vendor dependencies'),
                        Forms\Components\Checkbox::make('ci_cd_options.enabled_stages.build')
                            ->label('Build')
                            ->helperText('Builds assets'),
                        Forms\Components\Checkbox::make('ci_cd_options.enabled_stages.deploy')
                            ->label('Deploy')
                            ->helperText('Deploys to server')
                            ->disabled(),
                    ]),

                Forms\Components\Select::make('ci_cd_options.node_version')
                    ->label('Node.js version')
                    ->visible(fn (Forms\Get $get) => $get('ci_cd_options.enabled_stages.build'))
                    ->columnSpan(1)
                    ->options([
                        '20' => '20',
                        '18' => '18',
                        '16' => '16',
                        '14' => '14',
                        '12' => '12',
                    ])
                    ->required(fn (Forms\Get $get) => $get('ci_cd_options.enabled_stages.build')),

                Forms\Components\Repeater::make('Add stages to deploy')
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
                    ]),
            ]);
    }

    protected function isCiCdTemplateVersionAvailable(string $value): bool
    {
        return in_array($value, [
            '3.0',
            // '2.2',
        ]);
    }

    protected function getCiCdTemplateVersions(): array
    {
        return [
            '2.0' => '2.0 - Webpack',
            '2.1' => '2.1 - Vite',
            '2.2' => '2.2 - Vite + Composer stage',
            '3.0' => '3.0 - configurable',
        ];
    }
}
