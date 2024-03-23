<?php

namespace App\Filament\Dashboard\Pages;

use App\Parser\AccessParser;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Blade;
use Throwable;

/**
 * @property Form $form
 */
class ParseAccess extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.dashboard.pages.parse-access';

    public array $data = [];

    public array $types = [
        'json',
        'yaml',
        'php',
    ];
    public bool $parsed = false;

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    public function mount(): void
    {
        $input = <<<'DOC'
web.example.nwdev.ent
Domain: https://web.example.nwdev.ent
Host: web.example.nwdev.ent
Login: web-example-dev
Password: XxXxXxXXXXX

MySQL:
web-example-dev_db
web-example-dev_db
XXxxxxxXXXXXXxx
DOC;
        $parser = $this->parseAccessInput($input);

        $state['input'] = $input;
        $state['result'] = [
            'json' => $parser->makeJson(),
            'yaml' => $parser->makeYaml(),
        ];
        $this->fill(['parsed' => true]);

        $this->form->fill($state);
    }

    public function parse(): void
    {
        $state = $this->form->getState();

        $input = $state['input'];

        $parser = $this->parseAccessInput($input);

        $state['result'] = [
            'json' => $parser->makeJson(),
            'yaml' => $parser->makeYaml(),
        ];

        $this->form->fill($state);

        $this->fill(['parsed' => true]);

        Notification::make()
            ->title('Parsed!')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('parse')
                ->label('Parse')
                ->action('parse'),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(4)->schema([
                Forms\Components\Section::make('Access')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\Textarea::make('input')
                            ->label('Input')
                            ->autofocus()
                            ->live(onBlur: true)
                            ->required()
                            ->extraInputAttributes([
                                'rows' => 15,
                            ])
                            ->afterStateUpdated(function (?string $state) {
                                try {
                                    $this->parsed = false;

                                    if (!$state) {
                                        return;
                                    }

                                    $this->parseAccessInput($state);

                                    Notification::make()
                                        ->title('Content is valid')
                                        ->info()
                                        ->send();
                                } catch (Throwable $e) {
                                    Notification::make()
                                        ->title('Invalid content')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            }),
                    ]),

                Forms\Components\Section::make('Result')
                    ->columnSpan(3)
                    ->schema(function () {
                        $columns = [];

                        $types = [
                            'json',
                            'yaml',
                        ];

                        foreach ($types as $type) {
                            $columns[] = Forms\Components\Textarea::make('result.' . $type)
                                ->columnSpan(1)
                                ->hiddenLabel()
                                ->hintAction(
                                    Forms\Components\Actions\Action::make('copyResult')
                                        ->icon('heroicon-m-clipboard')
                                        ->visible(fn () => $this->parsed)
                                        ->action(function ($state) {
                                            Notification::make()->title('Copied!')->icon('heroicon-m-clipboard')->send();
                                            $this->js(
                                                Blade::render(
                                                    <<<'JS'
                                            window.navigator.clipboard.writeText(@js($copyableState))
                                            JS
                                                    ,
                                                    ['copyableState' => $state]
                                                )
                                            );
                                        })
                                )
                                ->readOnly()
                                ->extraInputAttributes([
                                    'rows' => 20,
                                ]);
                        }

                        return [
                            Forms\Components\Grid::make(1)->schema([
                                Forms\Components\ToggleButtons::make('download_type')
                                    ->label('Which format to download')
                                    ->inline()
                                    ->live()
                                    ->grouped()
                                    ->colors(array_combine($this->types, ['info', 'info', 'info',]))
                                    ->options(array_combine($this->types, $this->types)),

                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('Download')
                                        ->disabled(fn (Forms\Get $get) => !$get('download_type'))
                                        ->color(Color::Indigo)
                                        ->label('Download')
                                        ->action(function (Forms\Get $get) {
                                            $type = $get('download_type');

                                            if (!$type) {
                                                return null;
                                            }

                                            $state = $this->form->getState();
                                            $input = $state['input'];

                                            $parser = $this->parseAccessInput($input);

                                            $file = $parser->storeAsFile($type);

                                            return response()->download($file)->deleteFileAfterSend();
                                        }),
                                ]),
                            ]),

                            Forms\Components\Grid::make(count($types))
                                ->schema($columns),
                        ];
                    }),
            ]),
        ])->statePath('data');
    }

    public function parseAccessInput(mixed $input): AccessParser
    {
        $parser = new AccessParser();
        $parser->setInput($input);
        $parser->parse();

        return $parser;
    }
}
