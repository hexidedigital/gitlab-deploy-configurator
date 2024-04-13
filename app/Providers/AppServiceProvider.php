<?php

namespace App\Providers;

use App\Enums\Role;
use App\Events\DeployConfigurationJobFailedEvent;
use App\Models\User;
use App\Notifications\UserTelegramNotification;
use DefStudio\Telegraph\Models\TelegraphBot;
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
            return !is_null($user) && $user->hasMinAccess(Role::Root);
        });

        Authenticate::redirectUsing(function () {
            return Filament::getLoginUrl();
        });

        $this->app->bind(Telegram::class, static function () {
            $token = TelegraphBot::firstWhere('name', 'DeployConfigurationBot')?->token;

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
        Event::listen(function (Login $login) {
            if ($login->user instanceof User) {
                $login->user->notify(
                    new UserTelegramNotification(
                        TelegramMessage::create()
                            ->line("Hi, {$login->user->name}! We noticed that you have logged in.")
                            ->line('Time: ' . now()->format('Y-m-d H:i:s'))
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
                        ->line('Time: ' . now()->format('Y-m-d H:i:s'))
                        ->line('Error: ' . $event->exception?->getMessage())
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
                            ->line('Time: ' . now()->format('Y-m-d H:i:s'))
                            ->line('Error: ' . $event->exception?->getMessage())
                            ->button('Telescope', route('telescope', 'exceptions'))
                            ->button('Log-viewer', route('log-viewer.index'))
                    )
                );
            });
        });
    }
}
