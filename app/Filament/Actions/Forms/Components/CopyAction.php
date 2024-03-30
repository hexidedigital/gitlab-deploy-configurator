<?php

namespace App\Filament\Actions\Forms\Components;

use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;

class CopyAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-m-clipboard')
            ->label('Copy content')
            ->action(function ($state, Component $livewire) {
                Notification::make()->title('Copied!')->icon('heroicon-m-clipboard')->send();

                $livewire->js(
                    Blade::render(
                        'window.navigator.clipboard.writeText(@js($copyableState))',
                        ['copyableState' => $state]
                    )
                );
            });
    }
}
