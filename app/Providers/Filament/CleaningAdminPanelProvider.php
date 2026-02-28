<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetCleaningAdminLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class CleaningAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('cleaning-admin')
            ->path('cleaning-admin')
            ->login()
            ->brandName('Cleaning Admin Dashboard')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->font('Cairo', 'https://fonts.bunny.net/css?family=cairo:400,500,600,700')
            ->viteTheme('resources/css/filament/cleaning-admin/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Command Center'),
                NavigationGroup::make('Bookings'),
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Settings'),
                NavigationGroup::make('Permissions'),
            ])
            ->discoverResources(in: app_path('Filament/CleaningAdmin/Resources'), for: 'App\Filament\CleaningAdmin\Resources')
            ->discoverPages(in: app_path('Filament/CleaningAdmin/Pages'), for: 'App\Filament\CleaningAdmin\Pages')
            ->discoverWidgets(in: app_path('Filament/CleaningAdmin/Widgets'), for: 'App\Filament\CleaningAdmin\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                SetCleaningAdminLocale::class,
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
