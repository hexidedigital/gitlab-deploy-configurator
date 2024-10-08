<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Contacts\HasParserInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator\InteractsWithParser;
use App\Filament\Dashboard\Pages\DeployConfigurator\ParseAccessSchema;
use App\Filament\Dashboard\Pages\DeployConfigurator\SampleFormData;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithAccessFieldset;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * @property Form $form
 */
class ParseAccess extends Page implements Forms\Contracts\HasForms, HasParserInfo
{
    use InteractsWithForms;
    use WithAccessFieldset;
    use InteractsWithParser;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.base-edit-page';

    /**
     * Form state
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        // disable this page
        return false;
    }

    public function mount(): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        $domain = str(app(GeneralSettings::class)->gitlabDomain)->replace(['https://', 'http://'], '');
        $sampleFormData = new SampleFormData();
        $this->form->fill([
            'projectInfo' => [
                ...$sampleFormData->getProjectInfoData($user->gitlab_token),
                'selected_id' => '000',
                'name' => 'Sample project',
                'project_id' => '000',
                'git_url' => "git@{$domain}:namespace/sample-project.git",
                'web_url' => "https://{$domain}/namespace/sample-project",
            ],
            'stages' => $sampleFormData->getSampleStages(includeStage: true),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            ParseAccessSchema::make()
                ->parseConfigurations(function () {
                    return $this->form->getRawState();
                })
                ->stagesRepeater(function (Forms\Components\Repeater $repeater) {
                    $repeater->addable()->deletable();
                })
                ->showConfirmationCheckbox(false)
                ->showMoreConfigurationSection(false)
                ->showNameInput(),
        ])->statePath('data');
    }
}
