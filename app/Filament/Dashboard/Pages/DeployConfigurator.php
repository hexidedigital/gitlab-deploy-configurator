<?php

namespace App\Filament\Dashboard\Pages;

use App\Domains\DeployConfigurator\Data\CiCdOptions;
use App\Domains\DeployConfigurator\Data\ProjectDetails;
use App\Domains\DeployConfigurator\DeployConfigBuilder;
use App\Domains\DeployConfigurator\Jobs\ConfigureRepositoryJob;
use App\Filament\Contacts\HasParserInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator\InteractsWithParser;
use App\Filament\Dashboard\Pages\DeployConfigurator\SampleFormData;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithAccessFieldset;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithGitlabService;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithProjectInfoManage;
use App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;
use App\Models\User;
use App\Notifications\UserTelegramNotification;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * @property Form $form
 */
class DeployConfigurator extends Page implements HasForms, HasActions, HasParserInfo
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithGitlabService;
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
    public Carbon $openedAt;

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

        $this->openedAt = now();
    }

    /**
     * Action for last wizard step and form
     */
    public function setupRepository(DeployConfigBuilder $deployConfigBuilder): void
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

        $deployConfigBuilder->parseConfiguration($configurations);

        /** @var User $user */
        $user = Filament::auth()->user();

        /* todo - create project */

        dispatch(
            new ConfigureRepositoryJob(
                userId: $user->getAuthIdentifier(),
                projectDetails: $project = ProjectDetails::makeFromArray($this->data['projectInfo']),
                ciCdOptions: CiCdOptions::makeFromArray($this->data['ci_cd_options']),
                deployConfigurations: $deployConfigBuilder->buildDeployPrepareConfig(),
                stages: $deployConfigBuilder->processStages(),
                startAt: $this->openedAt,
            )
        );

        $this->fill(['jobDispatched' => true]);

        $user->notify(
            new UserTelegramNotification(
                TelegramMessage::create(
                    "Repository '{$project->name}' setup started. You will be notified when it is done."
                )
            )
        );

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
                ->schema(function (DeployConfigBuilder $deployConfigBuilder) {
                    if (!$this->jobDispatched) {
                        return [];
                    }

                    $configurations = $this->form->getRawState();

                    $deployConfigBuilder->parseConfiguration($configurations);

                    $stages = $deployConfigBuilder->processStages();

                    return [
                        Forms\Components\View::make('helpful-suggestion')
                            ->viewData([
                                'stages' => $stages,
                            ]),
                    ];
                }),
        ])->statePath('data');
    }

    public function resetProjectRelatedData(): void
    {
        $this->resetProjectInfo();

        $this->reset([
            //'isLaravelRepository',
            'emptyRepo',
        ]);

        $this->resetParsedState();

        // reset some form data to defaults
        $sampleFormData = new SampleFormData();
        $this->fill([
            'data.init_repository_script' => null,
            // reset selected ci cd options
            'data.ci_cd_options' => $sampleFormData->getCiCdOptions(),
            // reset stages
            'data.stages' => $sampleFormData->getSampleStages(),
        ]);
    }

    protected function resetProjectInfo(): void
    {
        $sampleFormData = new SampleFormData();

        $projectInfo = $sampleFormData->getProjectInfoData(data_get($this, 'data.projectInfo.token'));

        Arr::forget($projectInfo, 'selected_id');

        $this->fill(Arr::dot($projectInfo, 'data.projectInfo'));
    }
}
