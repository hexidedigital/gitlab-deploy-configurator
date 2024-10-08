<?php

namespace App\Filament\Dashboard\Pages;

use App\Domains\GitLab\GitLabService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Gitlab\Exception\RuntimeException;
use Illuminate\Support\HtmlString;

class Register extends \Filament\Pages\Auth\Register
{
    public bool $tokenValid = false;

    public function mount(): void
    {
        if (app(GeneralSettings::class)->released) {
            parent::mount();

            return;
        }

        // registration disabled
        $this->redirect('/');

        Notification::make()
            ->title('Registration disabled')
            ->body('Registration is currently disabled. Wait for the release')
            ->info()
            ->send();
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->visible(fn () => $this->tokenValid);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('auth_info')
                    ->label('')
                    ->hidden(fn () => $this->tokenValid)
                    ->content('Enter token and we will fetch your GitLab account data.'),
                $this->getGitLabTokenFormComponent(),
                $this->getNameFormComponent()->visible(fn () => $this->tokenValid),
                $this->getEmailFormComponent()->visible(fn () => $this->tokenValid),
                $this->getPasswordFormComponent()->visible(fn () => $this->tokenValid),
                $this->getPasswordConfirmationFormComponent()->visible(fn () => $this->tokenValid),
                Hidden::make('gitlab_id'),
                Hidden::make('avatar_url'),
            ]);
    }

    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->label(__('filament-panels::pages/auth/register.form.name.label'))
            ->required()
            ->readOnly()
            ->maxLength(255)
            ->autofocus();
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::pages/auth/register.form.email.label'))
            ->placeholder('user@hexide-digital.com')
            ->email()
            ->required()
            ->readOnly()
            ->maxLength(255)
            ->endsWith('@hexide-digital.com')
            ->unique($this->getUserModel());
    }

    protected function getGitLabTokenFormComponent(): Component
    {
        return TextInput::make('gitlab_token')
            ->label('GitLab auth token')
            ->required()
            ->autofocus()
            ->readOnly(fn () => $this->tokenValid)
            ->hidden(fn () => $this->tokenValid)
            ->dehydratedWhenHidden()
            ->helperText(new HtmlString('Read where to get this token <a href="https://github.com/hexidedigital/laravel-gitlab-deploy#gitlab-api-access-token" class="underline" target="_blank">here</a>'))
            ->maxLength(255)
            ->live()
            ->afterStateUpdated(function ($state, Set $set) {
                $this->tokenValid = false;
                $set('name', null);
                $set('email', null);

                if (!$state) {
                    return;
                }

                $manager = app(GitLabService::class)->authenticateUsing(token: $state)->gitLabManager();

                try {
                    $me = $manager->users()->me();

                    if ($me['locked']) {
                        Notification::make()
                            ->title('Your GitLab account is locked')
                            ->body('Please contact your administrator to unlock your account.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->tokenValid = true;

                    $set('gitlab_id', $me['id']);
                    $set('avatar_url', $me['avatar_url']);
                    $set('name', $me['name']);
                    $set('email', $me['email']);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Authentication error')
                        ->body(new HtmlString(sprintf('<p>%s</p>', $exception->getMessage())))
                        ->danger()
                        ->send();
                }
            })
            ->unique(ignoreRecord: true);
    }
}
