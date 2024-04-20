<?php

namespace App\Providers;

use App\Domains\DeployConfigurator\Events\DeployConfigurationJobFailedEvent;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\UserTelegramNotification;
use DefStudio\Telegraph\Models\TelegraphBot;
use Filament\Events\Auth\Registered;
use Filament\Facades\Filament;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\Telegram\Telegram;
use NotificationChannels\Telegram\TelegramMessage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            FilamentAsset::register([
                Js::make('confetti-js', Vite::asset('resources/js/confetti.js')),
            ]);
        } catch (ViteManifestNotFoundException) {
            // ignore..
        }

        Gate::define('viewLogViewer', function (?User $user) {
            return !is_null($user) && $user->isRoot();
        });

        Authenticate::redirectUsing(function () {
            return Filament::getLoginUrl();
        });

        $this->app->bind(Telegram::class, static function () {
            $token = TelegraphBot::firstWhere('name', config('app.main_telegram_bot'))?->token;

            return new Telegram(
                $token,
                app(HttpClient::class),
                config('services.telegram-bot-api.base_uri')
            );
        });

        $this->registerListeners();
    }

    protected function registerListeners(): void
    {
        Event::listen(function (Login $event) {
            $user = $event->user;
            if ($user instanceof User) {
                $user->notify(
                    new UserTelegramNotification(
                        TelegramMessage::create()
                            ->line("Hi, {$user->name}! We noticed that you have logged in.")
                            ->line('Time: ' . now()->format('Y-m-d H:i:s'))
                    )
                );
            }
        });

        Event::listen(function (Registered $event) {
            $user = $event->getUser();
            if ($user instanceof User) {
                $user->update(['role' => Role::Developer]);

                User::find(2)->notify(
                    new UserTelegramNotification(
                        TelegramMessage::create()
                            ->line("Registered new user: {$user->name}, {$user->email}")
                            ->line('Time: ' . now()->timezone('Europe/Kiev')->format('Y-m-d H:i:s'))
                    )
                );
            }
        });

        Event::listen(function (DeployConfigurationJobFailedEvent $event) {
            $event->user->notify(
                new UserTelegramNotification(
                    TelegramMessage::create()
                        ->line('Unfortunately, we failed to configure your repository.')
                        ->line('Project: ' . $event->projectData->name)
                        ->line('Time: ' . now()->timezone('Europe/Kiev')->format('Y-m-d H:i:s'))
                        ->escapedLine('Error: ' . $event->exception?->getMessage())
                )
            );

            $loggerBotToken = TelegraphBot::firstWhere('name', 'HexideDigitalAppNotifyBot')?->token;

            User::where('role', '>=', Role::Root->value)->each(function (User $user) use ($event, $loggerBotToken) {
                $user->notify(
                    new UserTelegramNotification(
                        TelegramMessage::create()
                            ->when($loggerBotToken)->token($loggerBotToken)
                            ->line('Failed to configure repository.')
                            ->line('Project: ' . $event->projectData->name)
                            ->line('User: ' . $event->user->name)
                            ->line('Time: ' . now()->timezone('Europe/Kiev')->format('Y-m-d H:i:s'))
                            ->escapedLine('Error: ' . $event->exception?->getMessage())
                            ->button('Telescope', route('telescope', 'exceptions'))
                            ->button('Log-viewer', route('log-viewer.index'))
                    )
                );
            });
        });
    }
}
