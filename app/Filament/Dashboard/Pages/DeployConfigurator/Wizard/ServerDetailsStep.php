<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use Filament\Forms;

class ServerDetailsStep extends Forms\Components\Wizard\Step
{
    public static function make(string $label = ''): static
    {
        return parent::make('Server details');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-server')
            ->schema([
                Forms\Components\Repeater::make('stages')
                    ->hiddenLabel()
                    ->itemLabel(fn (array $state) => str("Paths for **" . $state['name'] . "** server")->markdown()->toHtmlString())
                    ->grid(3)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->schema([
                        Forms\Components\TextInput::make('options.base_dir_pattern')
                            ->required(),
                        Forms\Components\TextInput::make('options.bin_composer')
                            ->required(),
                        Forms\Components\TextInput::make('options.bin_php')
                            ->required(),
                    ]),
            ]);
    }
}
