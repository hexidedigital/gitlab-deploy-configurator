<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Filament\Dashboard\Pages\DeployConfigurator;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\HtmlString;
use Throwable;

class GitlabStep extends Forms\Components\Wizard\Step
{
    public static function make(string $label = ''): static
    {
        return parent::make('GitLab');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('feathericon-gitlab')
            ->afterValidation(function (DeployConfigurator $livewire) {
                try {
                    $livewire->fetchProjectFromGitLab([
                        // try to make a request to check if the token is valid
                        'per_page' => 1,
                    ]);

                    Notification::make()->title('Access granted to GitLab')->success()->send();
                } catch (Throwable $throwable) {
                    Notification::make()
                        ->title('Failed to fetch projects')
                        ->body(new HtmlString(sprintf('<p>%s</p><p>%s</p>', $throwable::class, $throwable->getMessage())))
                        ->danger()
                        ->send();

                    throw new Halt();
                }
            })
            ->schema([
                Forms\Components\TextInput::make('projectInfo.token')
                    ->label('API auth token to access to the project')
                    ->helperText(new HtmlString('Read where to get this token <a href="https://github.com/hexidedigital/laravel-gitlab-deploy#gitlab-api-access-token" class="underline" target="_blank">here</a>'))
                    ->required(),
                Forms\Components\TextInput::make('projectInfo.domain')
                    ->label('Your GitLab domain')
                    ->readOnly()
                    ->required(),
            ]);
    }
}
