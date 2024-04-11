<?php

namespace App\Providers;

use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\ViteManifestNotFoundException;
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
    }
}
