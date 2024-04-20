<?php

namespace App\Filament\Dashboard\Pages;

use App\Models\User;
use App\Settings\GeneralSettings;
use DefStudio\Telegraph\Models\TelegraphBot;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;
use Illuminate\Contracts\Support\Htmlable;

class ManageGeneralSettings extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('General settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('General settings');
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isRoot();
    }

    public function form(Form $form): Form
    {
        $telegramBots = TelegraphBot::pluck('name', 'name');

        return $form
            ->schema([
                Forms\Components\Toggle::make('released')
                    ->label('Released project'),

                Forms\Components\TextInput::make('gitlabDomain')
                    ->label('GitLab Domain')
                    ->disabled()
                    ->readOnly()
                    ->placeholder('https://gitlab.com')
                    ->helperText('Enter the domain of your GitLab instance'),

                Forms\Components\Select::make('mainTelegramBot')
                    ->label('Main Telegram Bot')
                    ->helperText('Enter the name of your main Telegram bot')
                    ->options($telegramBots),

                Forms\Components\Select::make('loggerTelegramBot')
                    ->label('Logger Telegram Bot')
                    ->helperText('Enter the name of your logger Telegram bot')
                    ->options($telegramBots),
            ]);
    }
}
