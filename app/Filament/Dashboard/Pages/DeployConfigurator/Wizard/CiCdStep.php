<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use Filament\Forms;
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
            ->schema([
                Forms\Components\Select::make('ci_cd_options.template_version')
                    ->label('CI/CD template version')
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

                Forms\Components\Repeater::make('stages')
                    ->addActionLabel('Add new stage')
                    ->minItems(1)
                    ->grid(3)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Stage name (branch name)')
                            ->hiddenLabel()
                            ->distinct()
                            ->placeholder('dev/stage/master/prod')
                            ->datalist([
                                'dev',
                                'stage',
                                'master',
                                'prod',
                            ])
                            ->required(),
                    ]),
            ]);
    }

    protected function isCiCdTemplateVersionAvailable(string $value): bool
    {
        return $value === '3.0';
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
