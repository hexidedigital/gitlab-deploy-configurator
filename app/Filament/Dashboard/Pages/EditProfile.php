<?php

namespace App\Filament\Dashboard\Pages;

use App\Filament\Dashboard\Pages\DeployConfigurator\WithGitlab;
use App\Notifications\ProfileOpenedNotification;
use App\Notifications\UserTelegramNotification;
use DefStudio\Telegraph\Models\TelegraphBot;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Gitlab\Exception\RuntimeException;
use GrahamCampbell\GitLab\GitLabManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use NotificationChannels\Telegram\TelegramMessage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class EditProfile extends \Filament\Pages\Auth\EditProfile
{
    use WithGitlab;

    public ?string $qr_link = null;
    public bool $canConnectTelegram = false;

    public function mount(): void
    {
        $this->fillForm();

        $this->canConnectTelegram = empty(Auth::user()->telegram_id) || !Auth::user()->is_telegram_enabled;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getGitLabTokenFormComponent(),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                Hidden::make('avatar_url'),

                $this->getTelegramToggleComponent(),

                Placeholder::make('qr_code')
                    ->label('Scan to join Telegram')
                    ->visible(fn () => $this->canConnectTelegram && $this->qr_link)
                    ->content(fn () => str(QrCode::size(300)->generate($this->qr_link))->toHtmlString()),
            ]);
    }

    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->label(__('filament-panels::pages/auth/edit-profile.form.name.label'))
            ->required()
            ->readOnly()
            ->maxLength(255)
            ->autofocus();
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::pages/auth/edit-profile.form.email.label'))
            ->placeholder('user@hexide-digital.com')
            ->email()
            ->required()
            ->readOnly()
            ->maxLength(255)
            ->endsWith('@hexide-digital.com')
            ->unique(ignoreRecord: true);
    }

    protected function getGitLabTokenFormComponent(): Component
    {
        return TextInput::make('gitlab_token')
            ->label('GitLab auth token')
            ->required()
            ->password()
            ->revealable()
            ->helperText(new HtmlString('Read where to get this token <a href="https://github.com/hexidedigital/laravel-gitlab-deploy#gitlab-api-access-token" class="underline" target="_blank">here</a>'))
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                if (!$state) {
                    return;
                }

                $manager = app(GitLabManager::class);

                $this->authenticateGitlabManager($manager, $state, config('services.gitlab.url'));

                try {
                    $me = $manager->users()->me();

                    Notification::make()
                        ->title("Welcome to GitLab, {$me['username']}!")
                        ->success()
                        ->send();

                    if ($get('gitlab_id') !== $me['id']) {
                        Notification::make()
                            ->title('Detected token manipulation')
                            ->body(new HtmlString('The user id associated with the GitLab account does not match the id of the user with new token.'))
                            ->danger()
                            ->send();

                        $set('gitlab_token', '');

                        return;
                    }

                    $set('gitlab_id', $me['id']);
                    $set('avatar_url', $me['avatar_url']);
                    $set('name', $me['name']);
                    $set('email', $me['email']);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Authentication error')
                        ->body(new HtmlString(sprintf('<p>%s</p><p>%s</p>', $exception::class, $exception->getMessage())))
                        ->danger()
                        ->send();
                }
            })
            ->maxLength(255)
            ->unique(ignoreRecord: true);
    }

    protected function getTelegramToggleComponent(): Toggle
    {
        return Toggle::make('is_telegram_enabled')
            ->label('Integration with Telegram')
            ->hintIcon('heroicon-o-question-mark-circle')
            ->hintIconTooltip('Allows to send Deployer events to your Telegram account')
            ->onColor(Color::Blue)
            ->live()
            ->afterStateUpdated(function ($state, Set $set) {
                if (!$state) {
                    $this->canConnectTelegram = true;

                    Notification::make()
                        ->title('Telegram integration is disabled')
                        ->body('You no longer receive Deployer events in your Telegram account')
                        ->info()
                        ->send();

                    Auth::user()->update([
                        'is_telegram_enabled' => $state,
                    ]);

                    return;
                }

                $url = TelegraphBot::firstWhere('name', 'DeployConfigurationBot')?->url();
                if (!$url) {
                    Notification::make()
                        ->title('Telegram integration')
                        ->body('The Telegram bot is not available')
                        ->danger()
                        ->send();

                    $set('is_telegram_enabled', false);

                    return;
                }

                $token = Str::random();
                $this->qr_link = "{$url}?start={$token}";

                Auth::user()->update([
                    'telegram_token' => $token,
                ]);

                Notification::make()
                    ->title('Telegram integration')
                    ->body('Scan the QR code to connect your Telegram account')
                    ->info()
                    ->send();

                $set('is_telegram_enabled', false);
            });
    }
}
