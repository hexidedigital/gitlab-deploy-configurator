<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Contacts\HasParserInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator\InteractsWithParser;
use App\Filament\Dashboard\Pages\DeployConfigurator\ParseAccessSchema;
use App\Filament\Dashboard\Pages\DeployConfigurator\SampleFormData;
use App\Filament\Dashboard\Pages\DeployConfigurator\WithAccessFileldset;
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
    use WithAccessFileldset;
    use InteractsWithParser;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.base-edit-page';

    /**
     * Form state
     */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'stages' => (new SampleFormData())->getSampleStages(),
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
                ->showNameInput(),
        ])->statePath('data');
    }
}
