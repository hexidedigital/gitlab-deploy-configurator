<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use Filament\Forms;

trait WithAccessFieldset
{
    public function getServerFieldset(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('Server')
            ->columns(1)
            ->columnSpan(1)
            ->schema([
                Forms\Components\Placeholder::make('placeholder.accessInfo.server.domain')
                    ->label('Domain')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.domain')),
                Forms\Components\Placeholder::make('placeholder.accessInfo.server.host')
                    ->label('Host')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.host')),
                Forms\Components\Placeholder::make('placeholder.accessInfo.server.port')
                    ->label('Port')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.port') ?: 22),
                Forms\Components\Placeholder::make('placeholder.accessInfo.server.login')
                    ->label('Login')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.login')),
                Forms\Components\Placeholder::make('placeholder.accessInfo.server.password')
                    ->label('Password')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.server.password') ?: str('_(uses SSH key)_')
                        ->markdown()->toHtmlString()),
            ]);
    }

    public function getMySQLFieldset(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('MySQL')
            ->columns(1)
            ->columnSpan(1)
            ->visible(fn (Forms\Get $get) => !is_null($get('accessInfo.database')))
            ->schema([
                Forms\Components\Placeholder::make('placeholder.accessInfo.database.database')
                    ->label('Database')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.database.database')),
                Forms\Components\Placeholder::make('placeholder.accessInfo.database.username')
                    ->label('Username')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.database.username')),
                Forms\Components\Placeholder::make('placeholder.accessInfo.database.password')
                    ->label('Password')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.database.password')),
            ]);
    }

    public function getSMTPFieldset(): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make('SMTP')
            ->columns(1)
            ->columnSpan(1)
            ->visible(fn (Forms\Get $get) => !is_null($get('accessInfo.mail')))
            ->schema([
                Forms\Components\Placeholder::make('placeholder.accessInfo.mail.hostname')
                    ->label('Hostname')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.mail.hostname')),
                Forms\Components\Placeholder::make('placeholder.accessInfo.mail.username')
                    ->label('Username')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.mail.username')),
                Forms\Components\Placeholder::make('placeholder.accessInfo.mail.password')
                    ->label('Password')
                    ->content(fn (Forms\Get $get) => $get('accessInfo.mail.password')),
            ]);
    }
}
