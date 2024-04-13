<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
    }
}
