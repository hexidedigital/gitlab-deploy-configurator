<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Contacts\HasParserInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator\InteractsWithParser;
use App\Filament\Dashboard\Pages\DeployConfigurator\SampleFormData;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithAccessFileldset;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithGitlab;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithProjectInfoManage;
use App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;
use App\Models\User;
use App\Parser\DeployConfigBuilder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
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
    use WithAccessFileldset;
    use InteractsWithParser;

    protected static ?string $navigationIcon = 'heroicon-o-cog';

    protected static string $view = 'filament.pages.base-edit-page';

    /**
     * Form state
     */
    public array $data = [];

    public bool $emptyRepo = false;
    public bool $isLaravelRepository = false;

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

        // todo - select laravel 11 playground
        $this->selectProject('689');
        // todo - select deploy parser (empty project)
        //        $this->selectProject('700');
    }

    /**
     * Action for last wizard step and form
     */
    public function setupRepository(): void
    {
        $configurations = $this->form->getRawState();

        $deployConfigBuilder = new DeployConfigBuilder();
        $deployConfigBuilder->setConfigurations($configurations);

        dd([
            $this->form->getState(),
            $this->data,
            $deployConfigBuilder->buildDeployPrepareConfig(),
        ]);

        $this->createCommitWithConfigFiles();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Wizard::make()
                ->columnSpanFull()
                ->submitAction(
                    Forms\Components\Actions\Action::make('prepare repository')
                        ->label('Prepare repository')
                        ->icon('heroicon-o-rocket-launch')
                        ->color(Color::Green)
                        ->action('setupRepository'),
                )
                ->previousAction(function (Forms\Components\Actions\Action $action) {
                    $action->icon('heroicon-o-arrow-uturn-left');
                })
                ->nextAction(function (Forms\Components\Actions\Action $action) {
                    $action->icon('heroicon-o-chevron-double-right');
                })
                ->schema([
                    Wizard\GitlabStep::make(),              // step 1
                    Wizard\ProjectStep::make(),             // step 2
                    Wizard\CiCdStep::make(),                // step 3
                    Wizard\ServerDetailsStep::make(),       // step 4
                    Wizard\ParseAccessStep::make(),         // step 5
                    Wizard\ConfirmationStep::make(),        // step 6
                ]),
        ])->statePath('data');
    }

    protected function createCommitWithConfigFiles(): void
    {
        $project_id = 689;
        $stageName = 'test/dev/' . now()->format('His');

        $project = $this->findProject($project_id);

        $branches = collect($this->getGitLabManager()->repositories()->branches($project_id))
            ->keyBy('name');

        if (empty($branches)) {
            return;
        }

        return;
        // todo
        $branches
            ->filter(fn (array $branch) => str($branch)->startsWith('test'))
            ->each(function (array $branch) use ($project_id) {
                $this->getGitLabManager()->repositories()->deleteBranch($project_id, $branch['name']);
            });

        $defaultBranch = $project['default_branch'];
        if (!$branches->has($stageName)) {
            //            $newBranch = $this->getGitLabManager()->repositories()->createBranch($project_id, $stageName, $defaultBranch);
        }

        // https://docs.gitlab.com/ee/api/commits.html#create-a-commit-with-multiple-files-and-actions
        $this->getGitLabManager()->repositories()->createCommit($project_id, [
            "branch" => $stageName,
            "start_branch" => $defaultBranch,
            "commit_message" => "Configure deployment " . now()->format('H:i:s'),
            "author_name" => "DeployHelper",
            "author_email" => "deploy-helper@hexide-digital.com",
            "actions" => [
                [
                    "action" => "create",
                    "file_path" => ".deploy/config-encoded.yml",
                    "content" => base64_encode("test payload in base64"),
                    "encoding" => "base64",
                ],
                [
                    "action" => "create",
                    "file_path" => ".deploy/config-raw.yml",
                    "content" => "test payload in raw",
                ],
            ],
        ]);
    }
}
