<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\SetCleaningAdminLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $vite = app(Vite::class);
        $hasViteAssets = $vite->isRunningHot() || $vite->manifestHash() !== null;

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login();

        if ($hasViteAssets) {
            $panel->viteTheme('resources/css/filament/cleaning-admin/theme.css');
        }

        return $panel
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): HtmlString => $this->forceLatinDigitsScript(),
            )
            ->databaseNotifications()
            ->databaseNotificationsPolling('15s')
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
                SetCleaningAdminLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Force client-side Intl formatting (charts, widgets, JS-rendered dates/numbers)
     * to use Latin digits (0-9) instead of Arabic-Indic digits under the ar locale,
     * while keeping Arabic text.
     */
    private function forceLatinDigitsScript(): HtmlString
    {
        return new HtmlString(<<<'HTML'
        <script>
            (function () {
                const withLatin = (options) => {
                    const merged = options ? Object.assign({}, options) : {};
                    if (!merged.numberingSystem) {
                        merged.numberingSystem = 'latn';
                    }
                    return merged;
                };

                const patchIntl = (name) => {
                    const Original = Intl[name];
                    if (typeof Original !== 'function') {
                        return;
                    }
                    const Patched = function (locales, options) {
                        return new Original(locales, withLatin(options));
                    };
                    Patched.prototype = Original.prototype;
                    Patched.supportedLocalesOf = Original.supportedLocalesOf;
                    Intl[name] = Patched;
                };

                patchIntl('NumberFormat');
                patchIntl('DateTimeFormat');

                const patchToLocale = (target, method) => {
                    const original = target[method];
                    if (typeof original !== 'function') {
                        return;
                    }
                    target[method] = function (locales, options) {
                        return original.call(this, locales, withLatin(options));
                    };
                };

                patchToLocale(Number.prototype, 'toLocaleString');
                patchToLocale(Date.prototype, 'toLocaleString');
                patchToLocale(Date.prototype, 'toLocaleDateString');
                patchToLocale(Date.prototype, 'toLocaleTimeString');
            })();
        </script>
        HTML);
    }
}
