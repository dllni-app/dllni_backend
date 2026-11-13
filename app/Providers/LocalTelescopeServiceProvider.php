<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;

final class LocalTelescopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->environment('local') || ! config('telescope.enabled') || ! class_exists(TelescopeServiceProvider::class)) {
            return;
        }

        // Keep diagnostics completely separate from the business database.
        config(['database.connections.telescope_local' => [
            'driver' => 'sqlite',
            'database' => storage_path('app/private/telescope.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'wal',
        ]]);

        $this->app->register(TelescopeServiceProvider::class);
    }

    public function boot(): void
    {
        if (! $this->app->environment('local') || ! config('telescope.enabled') || ! class_exists(Telescope::class)) {
            return;
        }

        // Do not trust forwarded IP headers: only the actual host can use the UI.
        Telescope::auth(static fn (Request $request): bool => app()->environment('local')
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && in_array($request->getHost(), ['127.0.0.1', 'localhost', '::1', '[::1]'], true));

        Telescope::hideRequestHeaders(['authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'fcm-token', 'x-api-key']);
        Telescope::hideRequestParameters(['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'otp', 'code', '_token', 'fcm_token', 'pin']);

        // Persist API read diagnostics only, not logins, writes or Artisan commands.
        Telescope::filterBatch(static function (): bool {
            $request = request();

            return $request instanceof Request
                && $request->is('api/*')
                && ! $request->is(config('telescope.ignore_paths', []))
                && in_array($request->getRealMethod(), ['GET', 'HEAD'], true);
        });

        Telescope::tag(static fn (): array => request()->is('api/*') ? ['api-performance', request()->path()] : []);
    }
}
