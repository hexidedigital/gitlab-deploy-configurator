<?php

namespace App\Filament\Dashboard\Resources\UserResource\RelationManagers;

use App\Models\DeployProject;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeployProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'deployProjects';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required(),

                TextInput::make('type')
                    ->required(),

                TextInput::make('status')
                    ->required(),

                TextInput::make('current_step'),

                DatePicker::make('started_at')
                    ->label('Started Date'),

                DatePicker::make('finished_at')
                    ->label('Finished Date'),

                DatePicker::make('failed_at')
                    ->label('Failed Date'),

                DatePicker::make('canceled_at')
                    ->label('Canceled Date'),

                TextInput::make('fail_counts')
                    ->integer(),

                DatePicker::make('next_try_at')
                    ->label('Next Try Date'),

                Placeholder::make('created_at')
                    ->label('Created Date')
                    ->content(fn (?DeployProject $record): string => $record?->created_at?->diffForHumans() ?? '-'),

                Placeholder::make('updated_at')
                    ->label('Last Modified Date')
                    ->content(fn (?DeployProject $record): string => $record?->updated_at?->diffForHumans() ?? '-'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type'),

                TextColumn::make('status'),

                TextColumn::make('current_step'),

                TextColumn::make('started_at')
                    ->label('Started Date')
                    ->date(),

                TextColumn::make('finished_at')
                    ->label('Finished Date')
                    ->date(),

                TextColumn::make('failed_at')
                    ->label('Failed Date')
                    ->date(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
