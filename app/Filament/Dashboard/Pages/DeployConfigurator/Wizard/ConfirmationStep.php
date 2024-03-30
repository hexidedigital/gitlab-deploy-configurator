<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Filament\Dashboard\Pages\DeployConfigurator;
use Filament\Forms;
use Filament\Support\Exceptions\Halt;

class ConfirmationStep extends Forms\Components\Wizard\Step
{
    public static function make(string $label = ''): static
    {
        return parent::make('Confirmation');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-check-badge')
            ->afterValidation(function (Forms\Get $get, DeployConfigurator $livewire) {
                $isValid = $livewire->validateProjectData($get('projectInfo.selected_id'));
                if (!$isValid) {
                    throw new Halt();
                }
            })
            ->schema([
                Forms\Components\Section::make('Summary')
                    ->schema(function (DeployConfigurator $livewire) {
                        return [
                            Forms\Components\Placeholder::make('placeholder.name')
                                ->label('Repository')
                                ->content(fn (Forms\Get $get) => $get('projectInfo.name')),

                            Forms\Components\Repeater::make('stages')
                                ->itemLabel(fn (array $state) => $state['name'])
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->collapsible()
                                ->schema([
                                    Forms\Components\Grid::make(3)
                                        ->visible(fn (Forms\Get $get, DeployConfigurator $livewire) => $livewire->getParseStatusForStage($get('name')))
                                        ->schema([
                                            $livewire->getServerFieldset(),
                                            $livewire->getMySQLFieldset(),
                                            $livewire->getSMTPFieldset(),
                                        ]),
                                ]),
                        ];
                    }),

                Forms\Components\Checkbox::make('isReadyToDeploy')
                    ->label('I confirm that I have checked all the data and I am ready to deploy')
                    ->validationMessages([
                        'accepted' => 'You must confirm that you are ready to deploy.',
                    ])
                    ->accepted()
                    ->required(),
            ]);
    }
}
