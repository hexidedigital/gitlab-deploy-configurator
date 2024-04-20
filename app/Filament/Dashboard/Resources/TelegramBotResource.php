<?php

namespace App\Filament\Dashboard\Resources;

use App\Filament\Dashboard\Resources\TelegramBotResource\RelationManagers\ChatsRelationManager;
use App\Filament\Resources\TelegramBotResource\Pages;
use App\Models\User;
use DefStudio\Telegraph\Models\TelegraphBot;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TelegramBotResource extends Resource
{
    protected static ?string $model = TelegraphBot::class;

    protected static ?string $slug = 'telegram-bots';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Manage project';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isRoot();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required(),

                TextInput::make('token')
                    ->password()
                    ->revealable()
                    ->required(),

                Placeholder::make('created_at')
                    ->label('Created Date')
                    ->content(fn (?TelegraphBot $record): string => $record?->created_at?->diffForHumans() ?? '-'),

                Placeholder::make('updated_at')
                    ->label('Last Modified Date')
                    ->content(fn (?TelegraphBot $record): string => $record?->updated_at?->diffForHumans() ?? '-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChatsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Dashboard\Resources\TelegramBotResource\Pages\ListTelegramBots::route('/'),
            'create' => \App\Filament\Dashboard\Resources\TelegramBotResource\Pages\CreateTelegramBot::route('/create'),
            'edit' => \App\Filament\Dashboard\Resources\TelegramBotResource\Pages\EditTelegramBot::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }
}
