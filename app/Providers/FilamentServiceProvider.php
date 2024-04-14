<?php

namespace App\Providers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\Entry;
use Filament\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Filament\Support\get_attribute_translation;

class FilamentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureColumns();
        $this->configureInputs();

        Entry::configureUsing(function (Entry $entry): void {
            $entry->label(fn () => get_attribute_translation(
                str(class_basename($entry->getRecord()))->kebab()->plural(),
                $entry->getName(),
            ));
        });

        Page::$reportValidationErrorUsing = function (ValidationException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();
        };

        EditAction::configureUsing(fn (EditAction $action) => $action->iconButton());
        DeleteAction::configureUsing(fn (DeleteAction $action) => $action->iconButton());
        ViewAction::configureUsing(fn (ViewAction $action) => $action->iconButton());

        FilamentIcon::register(['actions::edit-action' => 'heroicon-s-pencil']);

        Notifications::alignment(Alignment::Right);

        FilamentView::registerRenderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('login-link'));
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END, fn () => view('meta'));
    }

    private function configureColumns(): void
    {
        Column::configureUsing(function (Column $column): void {
            $column->label(fn (Column $column) => get_attribute_translation(
                str(class_basename($column->getTable()->getModel()))->kebab()->plural(),
                $column->getName()
            ));
        });

        ToggleColumn::configureUsing(function (ToggleColumn $column): void {
            if (Str::contains($column->getName(), ['status'])) {
                $column
                    ->afterStateUpdated(function () {
                        Notification::make()
                            ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))
                            ->success()
                            ->send();
                    })
                    ->sortable();
            }
        });

        TextInputColumn::configureUsing(function (TextInputColumn $column): void {
            if (Str::contains($column->getName(), 'position')) {
                $column
                    ->afterStateUpdated(function () {
                        Notification::make()
                            ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))
                            ->success()
                            ->send();
                    })
                    ->sortable();
            }
        });
    }

    private function configureInputs(): void
    {
        Select::configureUsing(function (Select $field): void {
            $field->native(false);
        });

        DateTimePicker::configureUsing(function (DateTimePicker $field): void {
            $field->native(false);
        });

        DatePicker::configureUsing(function (DatePicker $field): void {
            $field->native(false);
        });

        Placeholder::configureUsing(function (Placeholder $placeholder): void {
            $placeholder->label(fn () => get_attribute_translation(
                str(class_basename($placeholder->getModelInstance()))->kebab()->plural(),
                $placeholder->getName(),
            ));
        });

        Field::configureUsing(function (Field $field): void {
            $field->label(fn () => get_attribute_translation(
                str(class_basename($field->getModelInstance()))->kebab()->plural(),
                $field->getName(),
            ));
        });

        FileUpload::configureUsing(function (FileUpload $field) {
            if (Str::of($field->getName())->contains(['image', 'logo'])) {
                $field
                    ->label('Зображення')
                    ->image()
                    ->hiddenLabel()
//                    ->columnSpanFull()
//                    ->required()
                    ->disk('public')
                    ->visibility('public')
                    ->preserveFilenames()
                    ->moveFiles()
                    ->directory('uploads/images')
                    ->imageEditor()
                    ->downloadable()
                    ->openable()
//                    ->imagePreviewHeight('300')
                    ->panelLayout('integrated');
            }
        }, isImportant: true);
    }
}
