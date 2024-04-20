<?php

namespace App\Filament\Dashboard\Resources\TelegramBotResource\Pages;

use App\Filament\Dashboard\Resources\TelegramBotResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramBot extends EditRecord
{
    protected static string $resource = TelegramBotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
