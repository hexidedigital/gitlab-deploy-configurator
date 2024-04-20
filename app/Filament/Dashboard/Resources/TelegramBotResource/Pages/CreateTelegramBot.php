<?php

namespace App\Filament\Dashboard\Resources\TelegramBotResource\Pages;

use App\Filament\Dashboard\Resources\TelegramBotResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTelegramBot extends CreateRecord
{
    protected static string $resource = TelegramBotResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
