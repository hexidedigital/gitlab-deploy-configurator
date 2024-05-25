<?php

namespace App\Filament\Dashboard\Pages;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\Data\CiCdOptions;
use App\Domains\DeployConfigurator\Data\ProjectDetails;
use App\Domains\DeployConfigurator\DeployConfigBuilder;
use App\Domains\DeployConfigurator\DeployProjectBuilder;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
    public bool $jobDispatched = false;
    public Carbon $openedAt;

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return null;
    }

    public function getTitle(): string|Htmlable
    {
        $project = $this->gitlabService()->ignoreOnGitlabException()->findProject(data_get($this, 'data.projectInfo.selected_id'));
        if (!$project) {
            return parent::getTitle();
        }

        return str(parent::getTitle())->append(" - <i>\"{$project->name}\"</i>")->toHtmlString();
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
                ->title('Repository setup already saved')
                ->body('You will be notified when it is done.')
                ->send();

            return;
        }

        $configurations = $this->form->getRawState();

        $deployConfigBuilder->parseConfiguration($configurations);

        /** @var User $user */
        $user = Filament::auth()->user();

        $projectDetails = ProjectDetails::makeFromArray($this->data['projectInfo']);
        $ciCdOptions = CiCdOptions::makeFromArray($this->data['ci_cd_options']);

        $deployProject = DeployProjectBuilder::make($projectDetails)
            ->user($user)
            ->openedAt($this->openedAt)
            ->ciCdOptions($ciCdOptions)
            ->stages($deployConfigBuilder->processStages())
            ->create('panel');

        dispatch(new ConfigureRepositoryJob(userId: $user->getAuthIdentifier(), deployProject: $deployProject));

        $this->fill(['jobDispatched' => true]);

        $user->notify(
            new UserTelegramNotification(
                TelegramMessage::create(
                    "Repository '{$projectDetails->name}' configuration saved. You will be notified when it is done."
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
                    Wizard\GitlabStep::make()->hidden(),
                    Wizard\ProjectStep::make(),
                    Wizard\CiCdStep::make(),
                    Wizard\ParseAccessStep::make(),
                    Wizard\ConfirmationStep::make(),
                ]),

            Forms\Components\Section::make('Configure notes')
                ->description('You can copy-paste values to configure your IDE')
                ->visible(fn () => $this->jobDispatched)
                ->schema(function (DeployConfigBuilder $deployConfigBuilder) {
                    if (!$this->jobDispatched) {
                        return [];
                    }

                    $configurations = $this->form->getRawState();

                    $deployConfigBuilder->parseConfiguration($configurations);

                    $stages = $deployConfigBuilder->processStages();
                    $ciCdOptions = CiCdOptions::makeFromArray($configurations['ci_cd_options']);
                    $templateInfo = (new CiCdTemplateRepository())->getTemplateInfo($ciCdOptions->template_group, $ciCdOptions->template_key);

                    $projectData = $this->resolveProject($configurations['projectInfo']['selected_id']);

                    return [
                        Forms\Components\View::make('deployer.helpful-suggestion')
                            ->viewData([
                                'stages' => $stages,
                                'projectDetails' => ProjectDetails::makeFromArray($configurations['projectInfo']),
                                'ciCdOptions' => $ciCdOptions,
                                'isBackend' => $templateInfo->group->isBackend(),
                                'templateInfo' => $templateInfo,
                                'user' => Auth::user(),
                                'projectUrl' => $projectData->web_url,
                            ]),
                    ];
                }),
        ])->statePath('data');
    }

    public function resetProjectRelatedData(): void
    {
        $this->resetProjectInfo();

        $this->reset([
            'emptyRepo',
        ]);

        $this->resetParserState();

        // reset some form data to defaults
        $sampleFormData = new SampleFormData();
        $this->fill([
            'data.init_repository_script' => null,
            // reset selected ci cd options
            'data.ci_cd_options' => $sampleFormData->getCiCdOptions(),
            // reset stages
            'data.stages' => $sampleFormData->getSampleStages(),
        ]);

        $this->form->fill($this->data);
    }

    protected function resetProjectInfo(): void
    {
        $sampleFormData = new SampleFormData();

        $projectInfo = $sampleFormData->getProjectInfoData(data_get($this, 'data.projectInfo.token'));

        Arr::forget($projectInfo, 'selected_id');

        $this->fill(Arr::dot($projectInfo, 'data.projectInfo'));
    }
}
