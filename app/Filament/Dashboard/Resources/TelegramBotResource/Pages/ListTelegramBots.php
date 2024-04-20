<?php

namespace App\Filament\Dashboard\Resources\TelegramBotResource\Pages;

use App\Filament\Dashboard\Resources\TelegramBotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTelegramBots extends ListRecords
{
    protected static string $resource = TelegramBotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
