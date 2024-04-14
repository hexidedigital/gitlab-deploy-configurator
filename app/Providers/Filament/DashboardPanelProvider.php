<?php

namespace App\Providers\Filament;

use App\Enums\Role;
use App\Filament\Dashboard\Pages\EditProfile;
use App\Filament\Dashboard\Pages\Register;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class DashboardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->spa()
            ->id('dashboard')
            ->path('')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->favicon(asset('favicon.png'))
            ->login()
            ->profile(EditProfile::class, false)
            ->registration(Register::class)
            ->globalSearch(false)
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('System')
                    ->icon('heroicon-o-cog-6-tooth'),
            ])
            ->navigationItems([
                NavigationItem::make()
                    ->label('Telescope')
                    ->group('System')
                    ->visible(fn () => Auth::user()->hasMinAccess(Role::Root))
                    ->url(fn () => route('telescope'), shouldOpenInNewTab: true),
                NavigationItem::make()
                    ->label('Log Viewer')
                    ->group('System')
                    ->visible(fn () => Auth::user()->hasMinAccess(Role::Root))
                    ->url(fn () => route('log-viewer.index'), shouldOpenInNewTab: true),
            ])
            ->discoverResources(in: app_path('Filament/Dashboard/Resources'), for: 'App\\Filament\\Dashboard\\Resources')
            ->discoverPages(in: app_path('Filament/Dashboard/Pages'), for: 'App\\Filament\\Dashboard\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Dashboard/Widgets'), for: 'App\\Filament\\Dashboard\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
