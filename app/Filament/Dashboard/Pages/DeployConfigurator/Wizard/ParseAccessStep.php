<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Filament\Contacts\HasParserInfo;
use App\Filament\Dashboard\Pages\DeployConfigurator;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

class ParseAccessStep extends Forms\Components\Wizard\Step
{
    public static function make(string $label = ''): static
    {
        return parent::make('Settings');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-cog-6-tooth')
            ->afterValidation(function (HasParserInfo $livewire) {
                if ($livewire->isAllAccessParsed()) {
                    Notification::make()->title('You have unresolved access data')->danger()->send();

                    throw new Halt();
                }

                if ($livewire->isAllConnectionCorrect()) {
                    Notification::make()->title('You need to connect to servers')->danger()->send();

                    throw new Halt();
                }
            })
            ->schema([
                DeployConfigurator\ParseAccessSchema::make()
                    ->parseConfigurations(function (DeployConfigurator $livewire) {
                        return $livewire->form->getRawState();
                    })
                    ->showMoreConfigurationSection(function (Forms\Get $get) {
                        $templateGroup = (new CiCdTemplateRepository())->getTemplateGroup($get('ci_cd_options.template_group'));

                        return is_null($templateGroup) || $templateGroup->isBackend();
                    }),
            ]);
    }
}
