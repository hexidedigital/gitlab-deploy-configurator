<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Contacts\HasParserInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator\InteractsWithParser;
use App\Filament\Dashboard\Pages\DeployConfigurator\SampleFormData;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithAccessFieldset;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithGitlab;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithProjectInfoManage;
use App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;
use App\GitLab\Deploy\Data\CiCdOptions;
use App\GitLab\Deploy\Data\ProjectDetails;
use App\Jobs\ConfigureRepositoryJob;
use App\Models\User;
use App\Parser\DeployConfigBuilder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;

/**
 * @property Form $form
 */
class DeployConfigurator extends Page implements HasForms, HasActions, HasParserInfo
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithGitlab;
    use WithProjectInfoManage;
    use WithAccessFieldset;
    use InteractsWithParser;

    protected static ?string $navigationIcon = 'heroicon-o-cog';

    protected static string $view = 'filament.dashboard.pages.deploy-configurator';

    /**
     * Form state
     */
    public array $data = [];

    public bool $emptyRepo = false;
    public bool $isLaravelRepository = false;
    public bool $jobDispatched = false;

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return null;
    }

    public function mount(): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        $sampleFormData = new SampleFormData();
        $this->form->fill([
            'projectInfo' => $sampleFormData->getProjectInfoData($user->gitlab_token),
            'ci_cd_options' => $sampleFormData->getCiCdOptions(),
            'stages' => $sampleFormData->getSampleStages(),
        ]);

        // auto select project for testing user
        if ($user->gitlab_id == 89) {
            $this->selectProject(ConfigureRepositoryJob::TEST_PROJECT);
        }
    }

    /**
     * Action for last wizard step and form
     */
    public function setupRepository(): void
    {
        $this->form->validate();

        if ($this->jobDispatched) {
            Notification::make()
                ->warning()
                ->title('Repository setup already started')
                ->body('Repository setup has been already started. You will be notified when it is done.')
                ->send();

            return;
        }

        $configurations = $this->form->getRawState();

        $deployConfigBuilder = new DeployConfigBuilder();
        $deployConfigBuilder->parseConfiguration($configurations);

        dispatch(
            new ConfigureRepositoryJob(
                userId: Filament::auth()->user()->getAuthIdentifier(),
                projectDetails: ProjectDetails::makeFromArray($this->data['projectInfo']),
                ciCdOptions: new CiCdOptions(
                    template_version: $this->data['ci_cd_options']['template_version'],
                    enabled_stages: $this->data['ci_cd_options']['enabled_stages'],
                    node_version: $this->data['ci_cd_options']['node_version'],
                ),
                deployConfigurations: $deployConfigBuilder->buildDeployPrepareConfig(),
            )
        );

        $this->fill(['jobDispatched' => true]);

        Notification::make()
            ->success()
            ->title('Repository setup started')
            ->body('Repository setup has been started. You will be notified when it is done.')
            ->send();

        $this->js('renderConfetti()');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Wizard::make()
                ->columnSpanFull()
                ->hidden(fn () => $this->jobDispatched)
                ->submitAction(
                    Forms\Components\Actions\Action::make('prepare repository')
                        ->label('Prepare repository')
                        ->icon('heroicon-o-rocket-launch')
                        ->color(Color::Green)
                        ->disabled(fn () => $this->jobDispatched)
                        ->action('setupRepository'),
                )
                ->previousAction(function (Forms\Components\Actions\Action $action) {
                    $action->icon('heroicon-o-arrow-uturn-left');
                })
                ->nextAction(function (Forms\Components\Actions\Action $action) {
                    $action->icon('heroicon-o-chevron-double-right');
                })
                ->schema([
                    Wizard\GitlabStep::make(),
                    Wizard\ProjectStep::make(),
                    Wizard\CiCdStep::make(),
                    Wizard\ParseAccessStep::make(),
                    Wizard\ConfirmationStep::make(),
                ]),

            Forms\Components\Section::make('Configure notes')
                ->visible(fn () => $this->jobDispatched)
                ->schema(function () {
                    if (!$this->jobDispatched) {
                        return [];
                    }

                    $configurations = $this->form->getRawState();

                    $deployConfigBuilder = new DeployConfigBuilder();
                    $deployConfigBuilder->parseConfiguration($configurations);

                    $config = $deployConfigBuilder->buildDeployPrepareConfig();

                    return [
                        Forms\Components\View::make('helpful-suggestion')
                            ->viewData([
                                'config' => $config,
                            ]),
                    ];
                }),
        ])->statePath('data');
    }
}
