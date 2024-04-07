<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator\Wizard;

use App\Filament\Dashboard\Pages\DeployConfigurator;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Gitlab\Exception\RuntimeException;
use Illuminate\Support\HtmlString;

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
                    $livewire->getGitLabManager()->users()->me();

                    Notification::make()->title('Access granted to GitLab')->success()->send();
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Authentication error')
                        ->body(new HtmlString(sprintf('<p>%s</p><p>%s</p>', $exception::class, $exception->getMessage())))
                        ->danger()
                        ->send();

                    throw new Halt();
                }
            })
            ->columns(3)
            ->schema([
                Forms\Components\TextInput::make('projectInfo.token')
                    ->label('API auth token to access to the project')
                    ->columnSpan(1)
                    ->password()
                    ->revealable()
                    ->helperText(new HtmlString('Read where to get this token <a href="https://github.com/hexidedigital/laravel-gitlab-deploy#gitlab-api-access-token" class="underline" target="_blank">here</a>'))
                    ->required(),

                Forms\Components\TextInput::make('projectInfo.domain')
                    ->label('Your GitLab domain')
                    ->columnSpan(1)
                    ->hidden()
                    ->readOnly()
                    ->required(),
            ]);
    }
}
